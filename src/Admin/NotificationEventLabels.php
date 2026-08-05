<?php
/**
 * Operator-facing labels for notification audit events.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

/**
 * Keeps raw notification event type strings out of operator UX.
 */
final class NotificationEventLabels {

	/**
	 * Human label for a notification event, or null when unrelated.
	 *
	 * @param string               $event_type Event type.
	 * @param array<string, mixed> $payload    Payload.
	 */
	public static function describe( string $event_type, array $payload = array() ): ?string {
		unset( $payload );

		switch ( $event_type ) {
			case 'notification.sent':
				return __( 'Tracking notification sent.', 'mp-commerce-fulfillment' );
			case 'notification.failed':
				return __( 'Tracking notification failed.', 'mp-commerce-fulfillment' );
			case 'notification.suppressed':
				return __( 'Tracking notification suppressed.', 'mp-commerce-fulfillment' );
			default:
				return null;
		}
	}
}
