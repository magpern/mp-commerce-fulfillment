<?php
/**
 * Integration tests for GET/POST /mpcf/v1/fulfillments/{id}/notes, against
 * the real composition root and a real database.
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
final class NotesControllerTest extends WP_UnitTestCase {

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

	public function test_add_note_and_list_notes_round_trip(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id = $this->seed_fulfillment();

		$add = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$id}/notes" );
		$add->set_body_params( array( 'body' => 'Customer called about delivery.' ) );

		$add_response = $this->server->dispatch( $add );

		self::assertSame( 201, $add_response->get_status() );
		self::assertSame( 'Customer called about delivery.', $add_response->get_data()['note']['body'] );

		$list_response = $this->server->dispatch( new WP_REST_Request( 'GET', "/mpcf/v1/fulfillments/{$id}/notes" ) );

		self::assertSame( 200, $list_response->get_status() );
		self::assertCount( 1, $list_response->get_data()['notes'] );
	}

	public function test_pinned_notes_render_first(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id = $this->seed_fulfillment();

		$unpinned = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$id}/notes" );
		$unpinned->set_body_params( array( 'body' => 'Unpinned note.' ) );
		$this->server->dispatch( $unpinned );

		$pinned = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$id}/notes" );
		$pinned->set_body_params(
			array(
				'body'      => 'Pinned note.',
				'is_pinned' => true,
			)
		);
		$this->server->dispatch( $pinned );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', "/mpcf/v1/fulfillments/{$id}/notes" ) );
		$notes    = $response->get_data()['notes'];

		self::assertSame( 'Pinned note.', $notes[0]['body'] );
	}

	public function test_add_note_is_forbidden_without_add_notes_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$id = $this->seed_fulfillment();

		$request = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$id}/notes" );
		$request->set_body_params( array( 'body' => 'Trying to add a note.' ) );

		$response = $this->server->dispatch( $request );

		self::assertSame( 403, $response->get_status() );
	}

	public function test_add_note_rejects_an_empty_body(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id = $this->seed_fulfillment();

		$request = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$id}/notes" );
		$request->set_body_params( array( 'body' => '   ' ) );

		$response = $this->server->dispatch( $request );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'mpcf_invalid_payload', $response->as_error()->get_error_code() );
	}
}
