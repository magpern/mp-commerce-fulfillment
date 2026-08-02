<?php
/**
 * Integration tests for PUT/DELETE /mpcf/v1/fulfillments/{id}/assignment,
 * against the real composition root and a real database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Api;

use MPCF\Capabilities;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Plugin;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Tests\Integration\Woo\OrderFactoryTrait;
use ReflectionClass;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Same real-composition-root dispatch discipline as
 * {@see FulfillmentsControllerTest}.
 */
final class AssignmentControllerTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;
	use OrderFactoryTrait;

	/**
	 * @var WpdbFulfillmentRepository
	 */
	private WpdbFulfillmentRepository $fulfillments;

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
	}

	private function seed_fulfillment(): int {
		$order = $this->create_paid_order( 1 );

		return $this->fulfillments->find_by_order_id( $order->get_id() )->id();
	}

	public function test_assign_and_unassign_round_trip(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$id      = $this->seed_fulfillment();
		$user_id = 42;

		$assign = new WP_REST_Request( 'PUT', "/mpcf/v1/fulfillments/{$id}/assignment" );
		$assign->set_body_params( array( 'user_id' => $user_id ) );

		$assign_response = $this->server->dispatch( $assign );

		self::assertSame( 200, $assign_response->get_status() );
		self::assertSame( $user_id, $this->fulfillments->find( $id )->assignee_id() );

		$unassign_response = $this->server->dispatch( new WP_REST_Request( 'DELETE', "/mpcf/v1/fulfillments/{$id}/assignment" ) );

		self::assertSame( 200, $unassign_response->get_status() );
		self::assertNull( $this->fulfillments->find( $id )->assignee_id() );
	}

	public function test_assign_404s_for_an_unknown_fulfillment(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$request = new WP_REST_Request( 'PUT', '/mpcf/v1/fulfillments/999999/assignment' );
		$request->set_body_params( array( 'user_id' => 42 ) );

		$response = $this->server->dispatch( $request );

		self::assertSame( 404, $response->get_status() );
	}

	public function test_assign_is_forbidden_for_a_role_without_process_fulfillments(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$id = $this->seed_fulfillment();

		$request = new WP_REST_Request( 'PUT', "/mpcf/v1/fulfillments/{$id}/assignment" );
		$request->set_body_params( array( 'user_id' => 42 ) );

		$response = $this->server->dispatch( $request );

		self::assertSame( 403, $response->get_status() );
		self::assertNull( $this->fulfillments->find( $id )->assignee_id() );
	}
}
