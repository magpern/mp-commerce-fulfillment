<?php
/**
 * Immutable snapshot of merchant notification configuration.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Notifications;

use MPCF\Domain\Notification\NotificationStrategy;

/**
 * Validated, transport-independent configuration for the future
 * notification pipeline (M5-C). No send/dispatch behavior.
 */
final class NotificationConfiguration {

	/**
	 * Delivery strategy.
	 *
	 * @var NotificationStrategy
	 */
	private NotificationStrategy $strategy;

	/**
	 * Default carrier id (always present in the carrier registry).
	 *
	 * @var string
	 */
	private string $default_carrier_id;

	/**
	 * From-name override for notification emails.
	 *
	 * @var string
	 */
	private string $sender_name;

	/**
	 * Reply-To address (empty = use store default at send time).
	 *
	 * @var string
	 */
	private string $reply_to_email;

	/**
	 * Footer appended under tracking links.
	 *
	 * @var string
	 */
	private string $tracking_message_footer;

	/**
	 * Default email subject line.
	 *
	 * @var string
	 */
	private string $default_email_subject;

	/**
	 * Optional introduction paragraph.
	 *
	 * @var string
	 */
	private string $email_introduction;

	/**
	 * Optional signature block.
	 *
	 * @var string
	 */
	private string $email_signature;

	/**
	 * Assembles an immutable configuration snapshot.
	 *
	 * @param NotificationStrategy $strategy                Delivery strategy.
	 * @param string               $default_carrier_id      Registry carrier id.
	 * @param string               $sender_name             Sender display name.
	 * @param string               $reply_to_email          Reply-To address or empty.
	 * @param string               $tracking_message_footer Tracking footer text.
	 * @param string               $default_email_subject   Subject line.
	 * @param string               $email_introduction      Optional introduction.
	 * @param string               $email_signature         Optional signature.
	 */
	public function __construct(
		NotificationStrategy $strategy,
		string $default_carrier_id,
		string $sender_name,
		string $reply_to_email,
		string $tracking_message_footer,
		string $default_email_subject,
		string $email_introduction,
		string $email_signature
	) {
		$this->strategy                = $strategy;
		$this->default_carrier_id      = $default_carrier_id;
		$this->sender_name             = $sender_name;
		$this->reply_to_email          = $reply_to_email;
		$this->tracking_message_footer = $tracking_message_footer;
		$this->default_email_subject   = $default_email_subject;
		$this->email_introduction      = $email_introduction;
		$this->email_signature         = $email_signature;
	}

	/**
	 * Delivery strategy.
	 */
	public function strategy(): NotificationStrategy {
		return $this->strategy;
	}

	/**
	 * Default carrier id (always registry-valid).
	 */
	public function default_carrier_id(): string {
		return $this->default_carrier_id;
	}

	/**
	 * Sender display name.
	 */
	public function sender_name(): string {
		return $this->sender_name;
	}

	/**
	 * Reply-To email, or empty.
	 */
	public function reply_to_email(): string {
		return $this->reply_to_email;
	}

	/**
	 * Tracking message footer.
	 */
	public function tracking_message_footer(): string {
		return $this->tracking_message_footer;
	}

	/**
	 * Default email subject.
	 */
	public function default_email_subject(): string {
		return $this->default_email_subject;
	}

	/**
	 * Optional email introduction.
	 */
	public function email_introduction(): string {
		return $this->email_introduction;
	}

	/**
	 * Optional email signature.
	 */
	public function email_signature(): string {
		return $this->email_signature;
	}

	/**
	 * Array shape for tests and future filters.
	 *
	 * @return array{
	 *     strategy: string,
	 *     default_carrier_id: string,
	 *     sender_name: string,
	 *     reply_to_email: string,
	 *     tracking_message_footer: string,
	 *     default_email_subject: string,
	 *     email_introduction: string,
	 *     email_signature: string
	 * }
	 */
	public function to_array(): array {
		return array(
			'strategy'                => $this->strategy->value(),
			'default_carrier_id'      => $this->default_carrier_id,
			'sender_name'             => $this->sender_name,
			'reply_to_email'          => $this->reply_to_email,
			'tracking_message_footer' => $this->tracking_message_footer,
			'default_email_subject'   => $this->default_email_subject,
			'email_introduction'      => $this->email_introduction,
			'email_signature'         => $this->email_signature,
		);
	}
}
