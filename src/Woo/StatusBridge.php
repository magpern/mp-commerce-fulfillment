<?php
/**
 * Outbound half of the WooCommerce status bridge.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Woo;

use MPCF\Application\EventSubscriber;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Settings;
use Throwable;
use WC_Order;

/**
 * Architecture Plan §6.6, outbound direction only — the inbound direction
 * (WC cancellation/refund/edits proposing fulfillment-state changes) is
 * {@see RefundObserver}'s responsibility, matching the `src/` layout's own
 * per-file split. Event-driven: subscribed to `fulfillment.state_changed`
 * (registered against {@see \MPCF\Application\EventDispatcher} in the
 * composition root), it only acts on the specific transition the default
 * mapping cares about — a fulfillment entering `shipped` — and only writes
 * once every fulfillment for the order has reached at least that point.
 *
 * WC is authoritative for the money lifecycle; this class never lets the
 * warehouse side drive it directly — it only ever calls
 * {@see WC_Order::update_status()}, the one platform-sanctioned way to move
 * an order between statuses, and only for the one outcome the architecture
 * specifies (default mapping: shipped -> `completed`).
 */
final class StatusBridge implements EventSubscriber {

	/**
	 * Fulfillment states that satisfy the outbound "shipped" condition — a
	 * fulfillment that has progressed past `shipped` (e.g. already
	 * `delivered` or `completed` via a shortcut edge) still counts.
	 */
	private const SHIPPED_OR_LATER_STATES = array( 'shipped', 'delivered', 'completed' );

	/**
	 * Reads the fulfillment that just transitioned, and every sibling
	 * fulfillment for the same order.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

	/**
	 * The outbound-enabled toggle.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Builds the bridge.
	 *
	 * @param FulfillmentRepository $fulfillments Reads fulfillments for the order being checked.
	 * @param Settings              $settings     The outbound-enabled toggle.
	 */
	public function __construct( FulfillmentRepository $fulfillments, Settings $settings ) {
		$this->fulfillments = $fulfillments;
		$this->settings     = $settings;
	}

	/**
	 * Reacts to a dispatched `fulfillment.state_changed` event.
	 *
	 * @param DomainEvent $event The event that was just appended to the audit log.
	 */
	public function handle( DomainEvent $event ): void {
		try {
			$this->maybe_complete_order( $event );
		} catch ( Throwable $exception ) {
			// Invariant I10: a bridge failure degrades to a logged problem,
			// never a fatal in whatever request happened to be the one that
			// triggered the transition (an admin screen action, the CLI
			// backfill, a future REST call).
			$this->log( $exception->getMessage() );
		}
	}

	/**
	 * The actual decision, isolated from {@see handle()}'s failure handling.
	 *
	 * @param DomainEvent $event The event that was just appended to the audit log.
	 */
	private function maybe_complete_order( DomainEvent $event ): void {
		if ( BridgeReentrancyGuard::is_active() ) {
			return;
		}

		if ( ! $this->settings->outbound_bridge_enabled() ) {
			return;
		}

		if ( 'shipped' !== ( $event->payload()['to'] ?? null ) ) {
			return;
		}

		$fulfillment_id = $event->fulfillment_id();

		if ( null === $fulfillment_id ) {
			return;
		}

		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return;
		}

		foreach ( $this->fulfillments->find_all_by_order_id( $fulfillment->order_id() ) as $sibling ) {
			if ( ! in_array( $sibling->state(), self::SHIPPED_OR_LATER_STATES, true ) ) {
				return;
			}
		}

		$this->complete_order( $fulfillment->order_id() );
	}

	/**
	 * Advances a WC order to `completed`, guarded against re-entrancy and
	 * against redoing a write that already happened.
	 *
	 * @param int $order_id Order to advance.
	 */
	private function complete_order( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( $order->has_status( 'completed' ) ) {
			// Idempotency for duplicate hooks: two fulfillments both
			// entering `shipped` in close succession both pass the "all
			// shipped" check, but only the first write should ever reach
			// WooCommerce.
			return;
		}

		BridgeReentrancyGuard::run(
			static function () use ( $order ): void {
				$order->update_status( 'completed', 'MPCF: all fulfillments have shipped — order marked complete automatically.' );
			}
		);
	}

	/**
	 * Records a bridge failure without ever surfacing it to the shopper or
	 * the operator who happened to trigger the transition.
	 *
	 * @param string $message Failure detail.
	 */
	private function log( string $message ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->error(
			"Outbound status bridge failed: {$message}",
			array( 'source' => 'mp-commerce-fulfillment' )
		);
	}
}
