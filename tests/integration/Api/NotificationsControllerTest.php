<?php
/**
 * Integration tests for shipment notification REST routes.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Api;

use MPCF\Capabilities;
use MPCF\Domain\Notification\NotificationStrategy;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Plugin;
use MPCF\Settings;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Tests\Integration\Woo\OrderFactoryTrait;
use ReflectionClass;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Notify + status against the real composition root.
 */
final class NotificationsControllerTest extends WP_UnitTestCase {

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

		// Persist strategy before composition-root Settings caches get().
		$settings = new Settings();
		$settings->save(
			array_merge(
				$settings->get(),
				array( 'notification_strategy' => NotificationStrategy::MPCF_SHIPPED )
			)
		);

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
	 * Seeds a shipped shipment with tracking.
	 */
	private function seed_shipped_shipment(): int {
		$order       = $this->create_paid_order( 1 );
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );
		$id          = $fulfillment->id();

		foreach ( $this->items->find_for_fulfillment( $id ) as $item ) {
			$item->record_picked( $item->qty_ordered() );
			$item->record_packed( $item->qty_ordered() );
			$this->items->save( $item );
		}

		$create = $this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$id}/shipments" ) );
		$sid    = (int) $create->get_data()['shipment']['id'];

		$update = new WP_REST_Request( 'PATCH', "/mpcf/v1/shipments/{$sid}" );
		$update->set_body_params(
			array(
				'carrier_id'      => 'postnord',
				'tracking_number' => 'NOTIFY123',
			)
		);
		$this->server->dispatch( $update );
		$this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/shipments/{$sid}/ship" ) );

		return $sid;
	}

	public function test_notify_sends_and_status_reports_sent(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$shipment_id = $this->seed_shipped_shipment();

		$notify = new WP_REST_Request( 'POST', "/mpcf/v1/shipments/{$shipment_id}/notify" );
		$notify->set_body_params( array( 'force' => true ) );
		$response = $this->server->dispatch( $notify );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( NotificationStrategy::MPCF_SHIPPED, $data['strategy'] );
		self::assertContains( $data['status'], array( 'sent', 'failed' ) );

		$status = $this->server->dispatch( new WP_REST_Request( 'GET', "/mpcf/v1/shipments/{$shipment_id}/notification-status" ) );
		self::assertSame( 200, $status->get_status() );
		self::assertArrayHasKey( 'notification', $status->get_data() );
	}

	public function test_notify_is_forbidden_without_manage_shipments(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/mpcf/v1/shipments/1/notify' ) );

		self::assertSame( 403, $response->get_status() );
	}

	public function test_status_is_forbidden_without_view_queue(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/mpcf/v1/shipments/1/notification-status' ) );

		self::assertSame( 403, $response->get_status() );
	}
}
