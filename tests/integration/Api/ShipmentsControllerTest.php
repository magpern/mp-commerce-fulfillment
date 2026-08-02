<?php
/**
 * Integration tests for the shipment REST routes, against the real
 * composition root and a real database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Api;

use MPCF\Capabilities;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
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
final class ShipmentsControllerTest extends WP_UnitTestCase {

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

	/**
	 * Seeds a fulfillment with one fully-packed item — ready for a
	 * shipment to auto-allocate against.
	 */
	private function seed_packed_fulfillment(): int {
		$order       = $this->create_paid_order( 2 );
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );
		$id          = $fulfillment->id();

		foreach ( $this->items->find_for_fulfillment( $id ) as $item ) {
			$item->record_picked( $item->qty_ordered() );
			$item->record_packed( $item->qty_ordered() );
			$this->items->save( $item );
		}

		return $id;
	}

	public function test_create_shipment_auto_allocates_packed_quantities_and_returns_the_envelope(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$fulfillment_id = $this->seed_packed_fulfillment();

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$fulfillment_id}/shipments" ) );

		self::assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( 'pending', $data['shipment']['status'] );
		self::assertSame( $fulfillment_id, $data['fulfillment']['id'] );
		self::assertNotEmpty( $data['transitions'] );

		$list = $this->server->dispatch( new WP_REST_Request( 'GET', "/mpcf/v1/fulfillments/{$fulfillment_id}/shipments" ) );
		$rows = $list->get_data()['shipments'];

		self::assertCount( 1, $rows );
		self::assertCount( 1, $rows[0]['packages'] );
		self::assertSame( 1, $rows[0]['packages'][0]['seq'] );
	}

	public function test_create_shipment_is_forbidden_for_a_role_without_manage_shipments(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$fulfillment_id = $this->seed_packed_fulfillment();

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$fulfillment_id}/shipments" ) );

		self::assertSame( 403, $response->get_status() );
	}

	public function test_create_shipment_404s_for_an_unknown_fulfillment(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/mpcf/v1/fulfillments/999999/shipments' ) );

		self::assertSame( 404, $response->get_status() );
	}

	public function test_update_shipment_sets_carrier_and_tracking(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$fulfillment_id = $this->seed_packed_fulfillment();
		$create         = $this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$fulfillment_id}/shipments" ) );
		$shipment_id    = $create->get_data()['shipment']['id'];

		$update = new WP_REST_Request( 'PATCH', "/mpcf/v1/shipments/{$shipment_id}" );
		$update->set_body_params(
			array(
				'carrier_id'      => 'postnord',
				'service'         => 'MyPack',
				'tracking_number' => 'ABC123',
			)
		);
		$response = $this->server->dispatch( $update );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( 'postnord', $data['shipment']['carrier_id'] );
		self::assertSame( 'ABC123', $data['shipment']['tracking_number'] );
	}

	public function test_ship_marks_the_shipment_shipped(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$fulfillment_id = $this->seed_packed_fulfillment();
		$create         = $this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$fulfillment_id}/shipments" ) );
		$shipment_id    = $create->get_data()['shipment']['id'];

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/shipments/{$shipment_id}/ship" ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'shipped', $response->get_data()['shipment']['status'] );
	}

	public function test_add_package_appends_the_next_sequence_number(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$fulfillment_id = $this->seed_packed_fulfillment();
		$create         = $this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$fulfillment_id}/shipments" ) );
		$shipment_id    = $create->get_data()['shipment']['id'];

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/shipments/{$shipment_id}/packages" ) );

		self::assertSame( 201, $response->get_status() );
		self::assertSame( 2, $response->get_data()['package']['seq'] );
	}

	public function test_delete_shipment_succeeds_while_pending_and_is_refused_once_shipped(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$fulfillment_id = $this->seed_packed_fulfillment();
		$create         = $this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$fulfillment_id}/shipments" ) );
		$shipment_id    = $create->get_data()['shipment']['id'];

		$this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/shipments/{$shipment_id}/ship" ) );

		$refused = $this->server->dispatch( new WP_REST_Request( 'DELETE', "/mpcf/v1/shipments/{$shipment_id}" ) );

		self::assertSame( 422, $refused->get_status() );
		self::assertSame( 'mpcf_guard_rejected', $refused->as_error()->get_error_code() );

		$second_shipment_id = $this->server->dispatch(
			new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$fulfillment_id}/shipments" )
		)->get_data()['shipment']['id'];

		$deleted = $this->server->dispatch( new WP_REST_Request( 'DELETE', "/mpcf/v1/shipments/{$second_shipment_id}" ) );

		self::assertSame( 200, $deleted->get_status() );
	}
}
