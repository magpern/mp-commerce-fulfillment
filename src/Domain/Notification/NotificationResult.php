<?php
/**
 * Result of attempting to deliver a notification.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Notification;

/**
 * Immutable delivery outcome. Safe for audit payloads (no recipient email).
 */
final class NotificationResult {

	/**
	 * Whether delivery succeeded.
	 *
	 * @var bool
	 */
	private bool $success;

	/**
	 * Channel that produced this result.
	 *
	 * @var string
	 */
	private string $channel_id;

	/**
	 * Machine-readable failure code, or empty on success.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * Human-readable failure message, or empty on success.
	 *
	 * @var string
	 */
	private string $error_message;

	/**
	 * Assembles a result. Prefer {@see success()} / {@see failure()}.
	 *
	 * @param bool   $success       Whether delivery succeeded.
	 * @param string $channel_id    Channel identifier.
	 * @param string $error_code    Failure code.
	 * @param string $error_message Failure message.
	 */
	private function __construct( bool $success, string $channel_id, string $error_code, string $error_message ) {
		$this->success       = $success;
		$this->channel_id    = $channel_id;
		$this->error_code    = $error_code;
		$this->error_message = $error_message;
	}

	/**
	 * Successful delivery.
	 *
	 * @param string $channel_id Channel identifier.
	 */
	public static function success( string $channel_id ): self {
		return new self( true, $channel_id, '', '' );
	}

	/**
	 * Failed delivery.
	 *
	 * @param string $channel_id    Channel identifier.
	 * @param string $error_code    Machine-readable code.
	 * @param string $error_message Human-readable message.
	 */
	public static function failure( string $channel_id, string $error_code, string $error_message ): self {
		return new self( false, $channel_id, $error_code, $error_message );
	}

	/**
	 * Whether delivery succeeded.
	 */
	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * Channel id.
	 */
	public function channel_id(): string {
		return $this->channel_id;
	}

	/**
	 * Failure code, or empty.
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Failure message, or empty.
	 */
	public function error_message(): string {
		return $this->error_message;
	}

	/**
	 * Audit-safe array (no recipient PII).
	 *
	 * @return array{success: bool, channel: string, error_code: string}
	 */
	public function to_audit_array(): array {
		return array(
			'success'    => $this->success,
			'channel'    => $this->channel_id,
			'error_code' => $this->error_code,
		);
	}
}
