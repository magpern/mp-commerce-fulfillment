<?php
/**
 * Integration tests for the package REST routes, against the real
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
final class PackagesControllerTest extends WP_UnitTestCase {

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
	 * @return array{0:int,1:int} Fulfillment id, its auto-created package's id.
	 */
	private function seed_shipment_with_package(): array {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$order       = $this->create_paid_order( 1 );
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );
		$id          = $fulfillment->id();

		foreach ( $this->items->find_for_fulfillment( $id ) as $item ) {
			$item->record_packed( $item->qty_ordered() );
			$this->items->save( $item );
		}

		$create = $this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$id}/shipments" ) );
		$rows   = $this->server->dispatch( new WP_REST_Request( 'GET', "/mpcf/v1/fulfillments/{$id}/shipments" ) )->get_data()['shipments'];

		return array( $id, $rows[0]['packages'][0]['id'] );
	}

	public function test_update_package_records_a_spec_and_colli_tracking_number(): void {
		list( $fulfillment_id, $package_id ) = $this->seed_shipment_with_package();

		$request = new WP_REST_Request( 'PATCH', "/mpcf/v1/packages/{$package_id}" );
		$request->set_body_params(
			array(
				'weight_grams'    => 1200,
				'length_mm'       => 300,
				'width_mm'        => 200,
				'height_mm'       => 100,
				'tracking_number' => 'COLLI-1',
			)
		);

		$response = $this->server->dispatch( $request );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( 1200, $data['package']['weight_grams'] );
		self::assertSame( 'COLLI-1', $data['package']['tracking_number'] );
		self::assertSame( $fulfillment_id, $data['fulfillment']['id'] );
	}

	public function test_remove_package_deletes_it(): void {
		list( , $package_id ) = $this->seed_shipment_with_package();

		$response = $this->server->dispatch( new WP_REST_Request( 'DELETE', "/mpcf/v1/packages/{$package_id}" ) );

		self::assertSame( 200, $response->get_status() );
		self::assertNull( $response->get_data()['package'] );
	}

	public function test_update_package_404s_for_an_unknown_id(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$request = new WP_REST_Request( 'PATCH', '/mpcf/v1/packages/999999' );
		$request->set_body_params( array( 'weight_grams' => 500 ) );

		$response = $this->server->dispatch( $request );

		self::assertSame( 404, $response->get_status() );
	}

	public function test_update_package_is_forbidden_for_a_role_without_manage_shipments(): void {
		list( , $package_id ) = $this->seed_shipment_with_package();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$request = new WP_REST_Request( 'PATCH', "/mpcf/v1/packages/{$package_id}" );
		$request->set_body_params( array( 'weight_grams' => 500 ) );

		$response = $this->server->dispatch( $request );

		self::assertSame( 403, $response->get_status() );
	}
}
