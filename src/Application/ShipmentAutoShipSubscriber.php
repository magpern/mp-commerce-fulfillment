<?php
/**
 * Ships every pending shipment when a fulfillment enters `shipped`.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use MPCF\Domain\Event\DomainEvent;

/**
 * Architecture Plan §IV.5.8 step 11: when the fulfillment itself
 * transitions to `shipped`, every shipment still `pending` on it ships
 * too. Event-driven, registered against the same {@see EventDispatcher}
 * {@see \MPCF\Woo\StatusBridge} reacts to the same event through, for an
 * unrelated reason — this keeps {@see WorkflowService} free of any
 * knowledge that shipments exist, and {@see ShippingService} free of any
 * knowledge of the workflow engine.
 */
final class ShipmentAutoShipSubscriber implements EventSubscriber {

	/**
	 * Ships every pending shipment on the fulfillment that just changed
	 * state.
	 *
	 * @var ShippingService
	 */
	private ShippingService $shipping;

	/**
	 * Builds the subscriber.
	 *
	 * @param ShippingService $shipping Ships every pending shipment on the fulfillment that just changed state.
	 */
	public function __construct( ShippingService $shipping ) {
		$this->shipping = $shipping;
	}

	/**
	 * Reacts to a dispatched `fulfillment.state_changed` event.
	 *
	 * @param DomainEvent $event The event that was just appended to the audit log.
	 */
	public function handle( DomainEvent $event ): void {
		if ( 'shipped' !== ( $event->payload()['to'] ?? null ) ) {
			return;
		}

		$fulfillment_id = $event->fulfillment_id();

		if ( null === $fulfillment_id ) {
			return;
		}

		$this->shipping->ship_all_pending_for_fulfillment( $fulfillment_id, $event->actor() );
	}
}
