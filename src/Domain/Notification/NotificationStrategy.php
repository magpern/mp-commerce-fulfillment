<?php
/**
 * How customer shipment notifications will be delivered.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Notification;

/**
 * Immutable notification delivery strategy. Persisted as a single enum —
 * never as scattered booleans. Future strategies are additive values.
 *
 * Naming note: the completed-order email strategy is `COMPLETED_EMAIL`
 * (not a store-platform token) so Domain stays free of confined adapter
 * markers; it still means “extend the store’s completed-order email”.
 */
final class NotificationStrategy {

	/**
	 * Tracking via the store’s completed-order email extension only.
	 */
	public const COMPLETED_EMAIL = 'COMPLETED_EMAIL';

	/**
	 * Dedicated MPCF shipped email only.
	 */
	public const MPCF_SHIPPED = 'MPCF_SHIPPED';

	/**
	 * Both completed-order email extension and MPCF shipped email.
	 */
	public const BOTH = 'BOTH';

	/**
	 * No customer tracking communication from MPCF.
	 */
	public const DISABLED = 'DISABLED';

	/**
	 * Canonical strategy values in stable display order.
	 *
	 * @var list<string>
	 */
	private const VALUES = array(
		self::COMPLETED_EMAIL,
		self::MPCF_SHIPPED,
		self::BOTH,
		self::DISABLED,
	);

	/**
	 * Strategy value.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Assembles a strategy value object.
	 *
	 * @param string $value Canonical strategy constant.
	 */
	private function __construct( string $value ) {
		$this->value = $value;
	}

	/**
	 * Default strategy when the outbound status bridge is typically enabled.
	 */
	public static function default(): self {
		return new self( self::COMPLETED_EMAIL );
	}

	/**
	 * Builds a strategy from a raw value, or null when unrecognized.
	 *
	 * @param mixed $raw Raw strategy value.
	 */
	public static function try_from( mixed $raw ): ?self {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		return in_array( $raw, self::VALUES, true ) ? new self( $raw ) : null;
	}

	/**
	 * Builds a strategy from a raw value, falling back to {@see default()}.
	 *
	 * @param mixed $raw Raw strategy value.
	 */
	public static function from( mixed $raw ): self {
		return self::try_from( $raw ) ?? self::default();
	}

	/**
	 * Every known strategy value.
	 *
	 * @return list<string>
	 */
	public static function values(): array {
		return self::VALUES;
	}

	/**
	 * Canonical string value.
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * Whether this strategy equals another.
	 *
	 * @param self $other Other strategy.
	 */
	public function equals( self $other ): bool {
		return $this->value === $other->value;
	}

	/**
	 * Whether the completed-order email extension will be used (M5-C+).
	 */
	public function includes_completed_email(): bool {
		return self::COMPLETED_EMAIL === $this->value || self::BOTH === $this->value;
	}

	/**
	 * Whether the dedicated MPCF shipped email will be used (M5-C+).
	 */
	public function includes_mpcf_shipped(): bool {
		return self::MPCF_SHIPPED === $this->value || self::BOTH === $this->value;
	}

	/**
	 * Whether customer tracking communication is disabled.
	 */
	public function is_disabled(): bool {
		return self::DISABLED === $this->value;
	}
}
