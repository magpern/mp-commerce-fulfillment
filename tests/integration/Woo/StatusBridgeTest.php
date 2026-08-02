<?php
/**
 * Integration tests for the outbound WooCommerce status bridge.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Woo;

use MPCF\Application\EventDispatcher;
use MPCF\Application\WorkflowService;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Engine\GuardRegistry;
use MPCF\Engine\WorkflowEngine;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\SystemClock;
use MPCF\Settings;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Woo\BridgeReentrancyGuard;
use MPCF\Woo\StatusBridge;
use WP_UnitTestCase;

/**
 * Drives a real fulfillment through the standard workflow to `shipped`
 * exactly as Milestone 2's workspace will once it exists — for now, the
 * only caller of `WorkflowService::transition()` in production is
 * `RefundObserver`'s inbound path, so forward progression is exercised the
 * same way `WorkflowServiceTest` already does, just against the real
 * database and with a real `StatusBridge` subscribed to react to it.
 */
final class StatusBridgeTest extends WP_UnitTestCase {

	use OrderFactoryTrait;
	use CleanFulfillmentTablesTrait;

	/**
	 * @var WpdbFulfillmentRepository
	 */
	private WpdbFulfillmentRepository $fulfillments;

	/**
	 * @var WpdbFulfillmentItemRepository
	 */
	private WpdbFulfillmentItemRepository $items;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();

		// The real, globally-registered IntakeHooks (from Plugin::init())
		// stay active here — every test below wants a real order to arrive
		// pre-ingested exactly as checkout would leave it, then drives the
		// rest of the workflow by hand (see this class's docblock).
		$this->fulfillments = new WpdbFulfillmentRepository();
		$this->items        = new WpdbFulfillmentItemRepository();
	}

	/**
	 * Builds a WorkflowService with a fresh dispatcher `$bridge` is
	 * subscribed to — mirrors exactly what `Plugin::wire_services()` does,
	 * so the bridge reacts to a real dispatched `fulfillment.state_changed`
	 * event, not a hand-constructed one.
	 */
	private function workflow_service_with_bridge( StatusBridge $bridge ): WorkflowService {
		$dispatcher = new EventDispatcher();
		$dispatcher->subscribe( 'fulfillment.state_changed', $bridge );

		return new WorkflowService(
			$this->fulfillments,
			$this->items,
			new WpdbEventRepository(),
			new WorkflowEngine( GuardRegistry::standard() ),
			$dispatcher,
			new SystemClock(),
			array( StandardWorkflow::NAME => StandardWorkflow::definition() )
		);
	}

	/**
	 * Ingests a real paid order and drives its fulfillment to `packed`,
	 * stopping one edge short of `shipped` so each test controls that last
	 * transition itself.
	 */
	private function seed_packed_fulfillment( WorkflowService $service ): int {
		$order       = $this->create_paid_order( 2 );
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );
		$id          = $fulfillment->id();

		foreach ( $this->items->find_for_fulfillment( $id ) as $item ) {
			$item->record_picked( $item->qty_ordered() );
			$this->items->save( $item );
		}

		$service->transition( $id, 'picking', Actor::system() );
		$service->transition( $id, 'picked', Actor::system() );
		$service->transition( $id, 'packing', Actor::system() );

		foreach ( $this->items->find_for_fulfillment( $id ) as $item ) {
			$item->record_packed( $item->qty_ordered() );
			$this->items->save( $item );
		}

		$service->transition( $id, 'packed', Actor::system(), null, true );

		return $id;
	}

	public function test_shipping_the_only_fulfillment_for_an_order_completes_it(): void {
		$bridge  = new StatusBridge( $this->fulfillments, new Settings( array( 'outbound_bridge_enabled' => true ) ) );
		$service = $this->workflow_service_with_bridge( $bridge );

		$id       = $this->seed_packed_fulfillment( $service );
		$order_id = $this->fulfillments->find( $id )->order_id();

		$outcome = $service->transition( $id, 'shipped', Actor::system(), null, true, true );

		self::assertTrue( $outcome->is_success() );

		$order = wc_get_order( $order_id );
		self::assertTrue( $order->has_status( 'completed' ), 'The order must be advanced to completed once its only fulfillment ships.' );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		self::assertNotEmpty(
			array_filter( $notes, static fn( $note ): bool => false !== strpos( $note->content, 'MPCF' ) ),
			'The status change must be recorded with an MPCF-prefixed order note.'
		);
	}

	public function test_the_bridge_never_touches_the_order_when_outbound_mapping_is_disabled(): void {
		$bridge  = new StatusBridge( $this->fulfillments, new Settings( array( 'outbound_bridge_enabled' => false ) ) );
		$service = $this->workflow_service_with_bridge( $bridge );

		$id       = $this->seed_packed_fulfillment( $service );
		$order_id = $this->fulfillments->find( $id )->order_id();

		$service->transition( $id, 'shipped', Actor::system(), null, true, true );

		$order = wc_get_order( $order_id );
		self::assertFalse( $order->has_status( 'completed' ), 'Disabling the outbound setting must leave the order status untouched.' );
	}

	public function test_completing_the_order_does_not_recursively_trigger_another_bridge_write(): void {
		$bridge  = new StatusBridge( $this->fulfillments, new Settings( array( 'outbound_bridge_enabled' => true ) ) );
		$service = $this->workflow_service_with_bridge( $bridge );

		$id       = $this->seed_packed_fulfillment( $service );
		$order_id = $this->fulfillments->find( $id )->order_id();

		$depth_during_write = null;

		add_action(
			'woocommerce_order_status_completed',
			static function () use ( &$depth_during_write ): void {
				// Captured from inside the very WC write the bridge made —
				// proves the guard is actually held at the moment a hook a
				// third party might listen to fires, not just around the
				// call site.
				$depth_during_write = BridgeReentrancyGuard::is_active();
			}
		);

		$service->transition( $id, 'shipped', Actor::system(), null, true, true );

		self::assertTrue( $depth_during_write, 'The re-entrancy guard must be held while the bridge-initiated WC write is in flight.' );
		self::assertFalse( BridgeReentrancyGuard::is_active(), 'The guard must be released once the write completes.' );
		self::assertSame( 1, did_action( 'woocommerce_order_status_completed' ), 'Exactly one completion write must happen, not a cascade.' );
	}

	public function test_a_duplicate_shipped_transition_attempt_does_not_re_complete_the_order(): void {
		$bridge  = new StatusBridge( $this->fulfillments, new Settings( array( 'outbound_bridge_enabled' => true ) ) );
		$service = $this->workflow_service_with_bridge( $bridge );

		$id       = $this->seed_packed_fulfillment( $service );
		$order_id = $this->fulfillments->find( $id )->order_id();

		$service->transition( $id, 'shipped', Actor::system(), null, true, true );
		self::assertSame( 1, did_action( 'woocommerce_order_status_completed' ) );

		// A second call for the same fulfillment is rejected by the engine
		// itself (already shipped, no such edge) — but even if a duplicate
		// hook somehow re-dispatched the event, the bridge's own
		// has_status('completed') check is the second line of defense.
		$second = $service->transition( $id, 'shipped', Actor::system(), null, true, true );

		self::assertFalse( $second->is_success() );
		self::assertSame( 1, did_action( 'woocommerce_order_status_completed' ), 'A rejected duplicate transition must not cause a second completion write.' );

		$order = wc_get_order( $order_id );
		self::assertTrue( $order->has_status( 'completed' ) );
	}
}
