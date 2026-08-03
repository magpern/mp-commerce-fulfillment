<?php
/**
 * Integration tests for GET/POST /mpcf/v1/fulfillments, against the real
 * composition root and a real database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Api;

use MPCF\Application\EventDispatcher;
use MPCF\Application\TransitionContextFactory;
use MPCF\Application\WorkflowService;
use MPCF\Capabilities;
use DateTimeImmutable;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Engine\GuardRegistry;
use MPCF\Engine\WorkflowEngine;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\Database\WpdbPackageRepository;
use MPCF\Infrastructure\Database\WpdbShipmentRepository;
use MPCF\Infrastructure\SystemClock;
use MPCF\Plugin;
use MPCF\Settings;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Tests\Integration\Woo\OrderFactoryTrait;
use ReflectionClass;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * `Plugin::instance()->init()` registers `mpcf/v1` on `rest_api_init`
 * exactly once per boot; every test here boots a fresh `Plugin` instance
 * against a fresh `WP_REST_Server`, the same reset pattern
 * `AdminStatusBridgeWiringTest` uses for the admin composition root — this
 * dispatches through the real, globally-registered routes, never a
 * hand-wired controller instance.
 */
final class FulfillmentsControllerTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;
	use OrderFactoryTrait;

	/**
	 * @var WpdbFulfillmentRepository
	 */
	private WpdbFulfillmentRepository $fulfillments;

	/**
	 * @var WpdbFulfillmentItemRepository
	 */
	private WpdbFulfillmentItemRepository $items;

	/**
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();
		Plugin::activate();

		$reflection = new ReflectionClass( Plugin::class );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		Plugin::instance()->init();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- A WordPress core global, not a plugin symbol.
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$this->fulfillments = new WpdbFulfillmentRepository();
		$this->items        = new WpdbFulfillmentItemRepository();
	}

	private function seed_fulfillment( int $order_id_seed ): int {
		$order       = $this->create_paid_order( 2 );
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );

		return $fulfillment->id();
	}

	public function test_list_fulfillments_returns_matching_rows_for_an_operator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$this->seed_fulfillment( 1001 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/mpcf/v1/fulfillments' ) );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( 1, $data['total'] );
		self::assertSame( 'queued', $data['items'][0]['state'] );
	}

	public function test_list_fulfillments_is_forbidden_for_a_role_without_view_queue(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/mpcf/v1/fulfillments' ) );

		self::assertSame( 403, $response->get_status() );
		self::assertSame( 'mpcf_forbidden', $response->as_error()->get_error_code() );
	}

	public function test_get_fulfillment_returns_the_fulfillment_and_its_items(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id = $this->seed_fulfillment( 2001 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', "/mpcf/v1/fulfillments/{$id}" ) );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( $id, $data['fulfillment']['id'] );
		self::assertCount( 1, $data['items'] );
		self::assertSame( 2, $data['items'][0]['qty_ordered'] );
	}

	public function test_get_fulfillment_recent_events_returns_only_the_5_most_recent(): void {
		// F23 (Architecture Plan §IV.10, risk M2-R11): this used to be
		// `array_slice($view->timeline(), -5)` — the same unbounded-fetch
		// pattern fixed everywhere else the timeline is read.
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id     = $this->seed_fulfillment( 2002 );
		$events = new WpdbEventRepository();
		$prev   = $events->last_hash_for_fulfillment( $id );

		for ( $i = 0; $i < 8; $i++ ) {
			$prev = $events->append(
				DomainEvent::for_fulfillment( $id, "test.marker_{$i}", Actor::system(), new DateTimeImmutable() ),
				$prev
			);
		}

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', "/mpcf/v1/fulfillments/{$id}" ) );

		self::assertSame( 200, $response->get_status() );
		$types = array_column( $response->get_data()['recent_events'], 'event_type' );

		self::assertCount( 5, $types );
		self::assertSame(
			array( 'test.marker_3', 'test.marker_4', 'test.marker_5', 'test.marker_6', 'test.marker_7' ),
			$types,
			'Must be the 5 most recent, oldest-first among themselves.'
		);
	}

	public function test_get_fulfillment_404s_for_an_unknown_id(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/mpcf/v1/fulfillments/999999' ) );

		self::assertSame( 404, $response->get_status() );
		self::assertSame( 'mpcf_not_found', $response->as_error()->get_error_code() );
	}

	public function test_list_transitions_returns_the_real_candidate_for_the_current_state(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id = $this->seed_fulfillment( 3001 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', "/mpcf/v1/fulfillments/{$id}/transitions" ) );

		self::assertSame( 200, $response->get_status() );
		$targets = array_column( $response->get_data()['transitions'], 'target' );
		self::assertContains( 'picking', $targets );
	}

	public function test_submit_transition_succeeds_and_returns_the_fresh_fulfillment_and_transitions(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id = $this->seed_fulfillment( 4001 );

		$request = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$id}/transitions" );
		$request->set_body_params(
			array(
				'target'  => 'picking',
				'version' => 1,
			)
		);

		$response = $this->server->dispatch( $request );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( 'picking', $data['fulfillment']['state'] );
		self::assertSame( 2, $data['fulfillment']['version'] );
		self::assertNotEmpty( $data['transitions'] );
		self::assertSame( 'picking', $this->fulfillments->find( $id )->state() );
	}

	public function test_submit_transition_is_rejected_with_422_when_a_guard_blocks_it(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id = $this->seed_fulfillment( 4002 );

		$first = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$id}/transitions" );
		$first->set_body_params(
			array(
				'target'  => 'picking',
				'version' => 1,
			)
		);
		$this->server->dispatch( $first );

		// Nothing has been picked yet, so picking -> picked must be
		// rejected by AllItemsPickedGuard specifically.
		$second = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$id}/transitions" );
		$second->set_body_params(
			array(
				'target'  => 'picked',
				'version' => 2,
			)
		);
		$response = $this->server->dispatch( $second );

		self::assertSame( 422, $response->get_status() );
		$error = $response->as_error();
		self::assertSame( 'mpcf_guard_rejected', $error->get_error_code() );
		self::assertSame( 'all_items_picked', $error->get_error_data()['guard'] );
		self::assertSame( 'picking', $this->fulfillments->find( $id )->state(), 'A rejected transition must not change the stored state.' );
	}

	public function test_submit_transition_is_forbidden_for_a_capability_the_actor_lacks(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id = $this->seed_fulfillment( 4003 );

		$request = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$id}/transitions" );
		$request->set_body_params(
			array(
				'target'  => 'cancelled',
				'reason'  => 'no longer needed',
				'version' => 1,
			)
		);

		$response = $this->server->dispatch( $request );

		self::assertSame( 403, $response->get_status() );
		self::assertSame( 'mpcf_forbidden', $response->as_error()->get_error_code() );
		self::assertSame( 'queued', $this->fulfillments->find( $id )->state() );
	}

	public function test_submit_transition_returns_409_on_a_stale_version(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id = $this->seed_fulfillment( 4004 );

		$request = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$id}/transitions" );
		$request->set_body_params(
			array(
				'target'  => 'picking',
				'version' => 999,
			)
		);

		$response = $this->server->dispatch( $request );

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'mpcf_version_conflict', $response->as_error()->get_error_code() );
		self::assertSame( 'queued', $this->fulfillments->find( $id )->state(), 'A version conflict must not change the stored state.' );
	}

	public function test_submit_transition_404s_for_an_unknown_fulfillment(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$request = new WP_REST_Request( 'POST', '/mpcf/v1/fulfillments/999999/transitions' );
		$request->set_body_params(
			array(
				'target'  => 'picking',
				'version' => 1,
			)
		);

		$response = $this->server->dispatch( $request );

		self::assertSame( 404, $response->get_status() );
		self::assertSame( 'mpcf_not_found', $response->as_error()->get_error_code() );
	}

	public function test_the_rest_path_and_the_service_path_produce_identical_outcomes(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		// Two independently seeded fulfillments, one advanced through the
		// real REST route, one through the same WorkflowService the REST
		// controller and every admin screen call, invoked directly —
		// Architecture Plan §IV.15 criterion 2: the two paths must
		// produce identical database outcomes.
		$rest_id   = $this->seed_fulfillment( 5001 );
		$direct_id = $this->seed_fulfillment( 5002 );

		$request = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$rest_id}/transitions" );
		$request->set_body_params(
			array(
				'target'  => 'picking',
				'version' => 1,
			)
		);
		$this->server->dispatch( $request );

		$workflow_service = new WorkflowService(
			$this->fulfillments,
			new WpdbEventRepository(),
			new WorkflowEngine( GuardRegistry::standard() ),
			new EventDispatcher(),
			new SystemClock(),
			array( StandardWorkflow::NAME => StandardWorkflow::definition() ),
			new TransitionContextFactory( $this->items, new WpdbShipmentRepository(), new WpdbPackageRepository(), new Settings( array() ) )
		);
		$workflow_service->transition( $direct_id, 'picking', Actor::user( get_current_user_id(), 'Operator' ) );

		$rest_result   = $this->fulfillments->find( $rest_id );
		$direct_result = $this->fulfillments->find( $direct_id );

		self::assertSame( $rest_result->state(), $direct_result->state() );
		self::assertSame( $rest_result->version(), $direct_result->version() );
	}
}
