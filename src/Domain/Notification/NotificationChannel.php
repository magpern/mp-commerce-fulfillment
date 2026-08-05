<?php
/**
 * Outbound notification channel port.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Notification;

/**
 * Transport-only. Implementations must not interpret shipment aggregates.
 */
interface NotificationChannel {

	/**
	 * Stable channel id (e.g. `email`).
	 */
	public function id(): string;

	/**
	 * Delivers one notification.
	 *
	 * @param Notification $notification Complete outbound request.
	 */
	public function send( Notification $notification ): NotificationResult;
}
