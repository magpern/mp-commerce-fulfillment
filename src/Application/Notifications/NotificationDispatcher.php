<?php
/**
 * Subscribes to shipment.shipped and runs the notification pipeline.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Notifications;

use MPCF\Application\EventSubscriber;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;

/**
 * Shipment-agnostic at the channel layer: this subscriber only extracts
 * shipment_id from the event and delegates to NotificationService.
 */
final class NotificationDispatcher implements EventSubscriber {

	/**
	 * Notification orchestration service.
	 *
	 * @var NotificationService
	 */
	private NotificationService $notifications;

	/**
	 * Builds the dispatcher subscriber.
	 *
	 * @param NotificationService $notifications Notification orchestration.
	 */
	public function __construct( NotificationService $notifications ) {
		$this->notifications = $notifications;
	}

	/**
	 * Handles shipment.shipped domain events.
	 *
	 * @param DomainEvent $event Domain event.
	 */
	public function handle( DomainEvent $event ): void {
		if ( 'shipment.shipped' !== $event->event_type() ) {
			return;
		}

		$payload     = $event->payload();
		$shipment_id = isset( $payload['shipment_id'] ) ? (int) $payload['shipment_id'] : 0;

		if ( $shipment_id <= 0 ) {
			return;
		}

		try {
			$this->notifications->notify_shipment( $shipment_id, Actor::system( 'NotificationDispatcher' ), false );
		} catch ( \Throwable $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- I10: never let notification failures break ship.
			unset( $exception );
		}
	}
}
