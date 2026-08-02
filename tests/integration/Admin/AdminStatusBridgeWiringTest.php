<?php
/**
 * Regression test for the v0.1.1 composition-root defect.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Admin;

use DateTimeImmutable;
use MPCF\Capabilities;
use MPCF\Domain\Shipping\Package;
use MPCF\Domain\Shipping\PackageSpec;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\Database\WpdbPackageRepository;
use MPCF\Infrastructure\Database\WpdbShipmentRepository;
use MPCF\Plugin;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Tests\Integration\Woo\OrderFactoryTrait;
use MPCF\Woo\BridgeReentrancyGuard;
use ReflectionClass;
use ReflectionFunction;
use WP_UnitTestCase;

/**
 * Before this fix, `Plugin::wire_admin()` built its own `WorkflowService`
 * against a fresh, subscriber-less `EventDispatcher` — a transition
 * submitted from the Fulfillment Detail screen (or the Queue) dispatched
 * `fulfillment.state_changed` to no one, so `Woo\StatusBridge` (subscribed
 * only to `wire_services()`'s dispatcher) never fired for it, even though
 * `Woo\StatusBridgeTest` was fully green — that test, like every other
 * `FulfillmentDetailPage`/`QueuePage` integration test, hand-builds its own
 * `WorkflowService`/`EventDispatcher` pair to exercise the page's logic in
 * isolation, which is exactly why none of them could have caught this: they
 * never touch `Plugin`'s actual composition root.
 *
 * This test does, deliberately: it boots the real `Plugin::instance()->init()`
 * object graph as an admin request would, then recovers and calls
 * `submit_transition()` on the *real* `FulfillmentDetailPage` instance
 * `wire_admin()` built for it — read back from the real `admin_menu`
 * registration `wire_admin()` makes, never a second, hand-wired instance
 * built just for this test.
 */
final class AdminStatusBridgeWiringTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;
	use OrderFactoryTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();
		Plugin::activate();

		// A fresh Plugin instance per test, exactly like MenuVisibilityTest:
		// init() is idempotent-by-instance (the `booted` flag), and the real
		// singleton may already be booted by an earlier test in this
		// process with is_admin() false.
		$reflection = new ReflectionClass( Plugin::class );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	/**
	 * Boots the real Plugin object graph as an admin request would
	 * (`set_current_screen()` is what makes `is_admin()` true, exactly as
	 * `MenuVisibilityTest` relies on), fires the real `admin_menu` action,
	 * and recovers the real `FulfillmentDetailPage` instance `wire_admin()`
	 * built and closed over when registering its hidden submenu page —
	 * `Plugin.php`'s own `add_action( 'admin_menu', function () use
	 * ( $detail_page ) {...}, 20 )` — via `ReflectionFunction`'s bound
	 * `use()` variables. This is reading back what the real composition
	 * root actually built, not re-wiring an equivalent by hand.
	 *
	 * Priority 20 on `admin_menu` is not exclusively this plugin's — a real
	 * WooCommerce install may hook something of its own at the same
	 * priority (observed on the floor WC coordinate), so every registered
	 * callback at every priority is inspected rather than assuming the
	 * first one found is ours; non-`Closure` callbacks (arrays, strings)
	 * are skipped outright, since only a `Closure` can be reflected this
	 * way in the first place.
	 */
	private function boot_real_detail_page(): \MPCF\Admin\FulfillmentDetailPage {
		set_current_screen( 'dashboard' );

		global $menu, $submenu;
		$menu    = array();
		$submenu = array();

		Plugin::instance()->init();
		do_action( 'admin_menu' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$hook = $GLOBALS['wp_filter']['admin_menu'] ?? null;
		self::assertNotNull( $hook, 'Plugin::wire_admin() must register an admin_menu callback.' );

		foreach ( $hook->callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $registration ) {
				$callback = $registration['function'];

				if ( ! $callback instanceof \Closure ) {
					continue;
				}

				$bound = ( new ReflectionFunction( $callback ) )->getStaticVariables();

				if ( isset( $bound['detail_page'] ) && $bound['detail_page'] instanceof \MPCF\Admin\FulfillmentDetailPage ) {
					return $bound['detail_page'];
				}
			}
		}

		self::fail( 'No admin_menu callback closing over a FulfillmentDetailPage instance was found — did Plugin::wire_admin() change how it registers this submenu?' );
	}

	public function test_an_admin_initiated_shipped_transition_reaches_the_status_bridge(): void {
		$detail_page = $this->boot_real_detail_page();

		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$fulfillments = new WpdbFulfillmentRepository();
		$items        = new WpdbFulfillmentItemRepository();

		// Real IntakeHooks, freshly registered by boot_real_detail_page()'s
		// Plugin::instance()->init() call above, ingest this order exactly
		// as a real checkout would.
		$order       = $this->create_paid_order( 2 );
		$fulfillment = $fulfillments->find_by_order_id( $order->get_id() );
		$id          = $fulfillment->id();
		$order_id    = $fulfillment->order_id();

		foreach ( $items->find_for_fulfillment( $id ) as $item ) {
			$item->record_picked( $item->qty_ordered() );
			$items->save( $item );
		}

		self::assertNull( $detail_page->submit_transition( $id, 'picking', null ) );
		self::assertNull( $detail_page->submit_transition( $id, 'picked', null ) );
		self::assertNull( $detail_page->submit_transition( $id, 'packing', null ) );

		foreach ( $items->find_for_fulfillment( $id ) as $item ) {
			$item->record_packed( $item->qty_ordered() );
			$items->save( $item );
		}

		// A real shipment + weighed package — package_spec_present and
		// has_shipment are derived from this real data since Milestone 2
		// (Architecture Plan §IV.3.B, findings B/C/D), never asserted by
		// the caller.
		$shipments   = new WpdbShipmentRepository();
		$packages    = new WpdbPackageRepository();
		$shipment_id = $shipments->insert( Shipment::create( $id, new DateTimeImmutable() ) );
		$package     = Package::create( $shipment_id, 1, new DateTimeImmutable() );
		$package->set_spec( PackageSpec::create( 500, null, null, null ) );
		$packages->insert( $package );

		self::assertNull( $detail_page->submit_transition( $id, 'packed', null ) );
		self::assertFalse( wc_get_order( $order_id )->has_status( 'completed' ), 'Sanity check: the order must not already be completed before the last transition.' );

		$error = $detail_page->submit_transition( $id, 'shipped', null );

		self::assertNull( $error, 'The shipped transition itself must succeed.' );
		self::assertTrue(
			wc_get_order( $order_id )->has_status( 'completed' ),
			'An admin-initiated "shipped" transition on the last open fulfillment for an order must reach Woo\StatusBridge and move the WC order to completed — this is the v0.1.1 fix for the previously subscriber-less admin-side EventDispatcher.'
		);

		self::assertFalse( BridgeReentrancyGuard::is_active(), 'The re-entrancy guard must be released once the bridge write completes.' );
		self::assertSame( 1, did_action( 'woocommerce_order_status_completed' ), 'Exactly one completion write must happen, not a recursive cascade.' );

		self::assertSame(
			Shipment::STATUS_SHIPPED,
			$shipments->find( $shipment_id )->status(),
			'The fulfillment reaching "shipped" must cascade to every shipment still pending on it (Architecture Plan §IV.5.8 step 11), via the real ShipmentAutoShipSubscriber Plugin::wire_services() registers — not a hand-wired equivalent.'
		);
	}

	public function test_an_admin_initiated_cancellation_is_still_capability_checked(): void {
		// Not the defect this patch fixes, but a cheap adjacent proof that
		// sharing the object graph did not also loosen a capability check:
		// an operator (no mpcf_cancel_fulfillment) must still be refused.
		$detail_page = $this->boot_real_detail_page();

		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$fulfillments = new WpdbFulfillmentRepository();
		$order        = $this->create_paid_order( 1 );
		$fulfillment  = $fulfillments->find_by_order_id( $order->get_id() );

		$error = $detail_page->submit_transition( $fulfillment->id(), 'cancelled', 'no longer needed' );

		self::assertNotNull( $error, 'An operator must not be able to cancel a fulfillment.' );
		self::assertFalse( $fulfillments->find( $fulfillment->id() )->state() === 'cancelled' );
	}
}
