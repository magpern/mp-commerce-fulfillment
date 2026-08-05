<?php
/**
 * Recording email channel for notification tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Notifications;

use MPCF\Domain\Notification\Notification;
use MPCF\Domain\Notification\NotificationChannel;
use MPCF\Domain\Notification\NotificationResult;

/**
 * Records sends for assertions.
 */
final class RecordingEmailChannel implements NotificationChannel {

	/**
	 * Send count.
	 *
	 * @var int
	 */
	public int $send_count = 0;

	/**
	 * Last notification sent.
	 *
	 * @var Notification|null
	 */
	public ?Notification $last = null;

	/**
	 * Channel id.
	 */
	public function id(): string {
		return 'email';
	}

	/**
	 * Records the notification and returns success.
	 *
	 * @param Notification $notification Notification.
	 */
	public function send( Notification $notification ): NotificationResult {
		++$this->send_count;
		$this->last = $notification;

		return NotificationResult::success( $this->id() );
	}
}
