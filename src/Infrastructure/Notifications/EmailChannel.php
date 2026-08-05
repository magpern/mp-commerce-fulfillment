<?php
/**
 * Email transport for notifications.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Notifications;

use MPCF\Domain\Notification\Notification;
use MPCF\Domain\Notification\NotificationChannel;
use MPCF\Domain\Notification\NotificationResult;

/**
 * Sends via wp_mail. No shipment semantics.
 */
final class EmailChannel implements NotificationChannel {

	/**
	 * Channel identifier.
	 */
	public function id(): string {
		return 'email';
	}

	/**
	 * Delivers one notification via wp_mail.
	 *
	 * @param Notification $notification Complete outbound request.
	 */
	public function send( Notification $notification ): NotificationResult {
		if ( '' === $notification->recipient_email() ) {
			return NotificationResult::failure( $this->id(), 'missing_recipient', 'Recipient email is empty.' );
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$meta    = $notification->metadata();
		$sender  = isset( $meta['sender_name'] ) ? (string) $meta['sender_name'] : '';
		$reply   = isset( $meta['reply_to'] ) ? (string) $meta['reply_to'] : '';

		if ( '' !== $sender ) {
			$from_email = (string) get_option( 'admin_email' );
			$headers[]  = 'From: ' . $this->format_address( $sender, $from_email );
		}

		if ( '' !== $reply && is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		$sent = wp_mail(
			$notification->recipient_email(),
			$notification->subject(),
			$notification->html_body(),
			$headers
		);

		if ( ! $sent ) {
			return NotificationResult::failure( $this->id(), 'wp_mail_failed', 'wp_mail returned false.' );
		}

		return NotificationResult::success( $this->id() );
	}

	/**
	 * Formats a From header value.
	 *
	 * @param string $name  Display name.
	 * @param string $email Address.
	 */
	private function format_address( string $name, string $email ): string {
		$safe_name = str_replace( array( "\r", "\n", '"' ), '', $name );

		return sprintf( '"%s" <%s>', $safe_name, $email );
	}
}
