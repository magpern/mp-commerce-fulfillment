<?php
/**
 * Analytics time range DTO (UTC).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Analytics;

use DateTimeImmutable;
use InvalidArgumentException;
use MPCF\Engine\Analytics\UtcDay;

/**
 * Parsed report/overview range. All instants are UTC.
 */
final class AnalyticsRange {

	public const PRESET_TODAY = 'today';

	public const PRESET_DAILY = 'daily';

	public const PRESET_WEEKLY = 'weekly';

	public const PRESET_MONTHLY = 'monthly';

	public const PRESET_CUSTOM = 'custom';

	public const MAX_CUSTOM_DAYS = 93;

	/**
	 * Preset identifier.
	 *
	 * @var string
	 */
	private string $preset;

	/**
	 * Inclusive range start (UTC).
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $from;

	/**
	 * Exclusive range end (UTC).
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $to_exclusive;

	/**
	 * Builds a range.
	 *
	 * @param string            $preset       Preset identifier.
	 * @param DateTimeImmutable $from         Inclusive start.
	 * @param DateTimeImmutable $to_exclusive Exclusive end.
	 */
	private function __construct( string $preset, DateTimeImmutable $from, DateTimeImmutable $to_exclusive ) {
		$this->preset       = $preset;
		$this->from         = $from;
		$this->to_exclusive = $to_exclusive;
	}

	/**
	 * Current UTC calendar day as a half-open window.
	 *
	 * @param DateTimeImmutable $now Reference "now".
	 */
	public static function today( DateTimeImmutable $now ): self {
		$start = UtcDay::start( $now );

		return new self( self::PRESET_TODAY, $start, UtcDay::end_exclusive( $now ) );
	}

	/**
	 * Last `$days` closed UTC days ending at today's start.
	 *
	 * @param DateTimeImmutable $now  Reference "now".
	 * @param int               $days Number of closed days.
	 */
	public static function last_n_closed_days( DateTimeImmutable $now, int $days ): self {
		$today = UtcDay::start( $now );
		$from  = $today->modify( sprintf( '-%d days', $days ) );

		return new self( self::PRESET_CUSTOM, $from, $today );
	}

	/**
	 * Last 7 closed UTC days.
	 *
	 * @param DateTimeImmutable $now Reference "now".
	 */
	public static function weekly( DateTimeImmutable $now ): self {
		return self::last_n_closed_days( $now, 7 )->with_preset( self::PRESET_WEEKLY );
	}

	/**
	 * Last 30 closed UTC days.
	 *
	 * @param DateTimeImmutable $now Reference "now".
	 */
	public static function monthly( DateTimeImmutable $now ): self {
		return self::last_n_closed_days( $now, 30 )->with_preset( self::PRESET_MONTHLY );
	}

	/**
	 * Custom inclusive UTC day range (capped; today mixed via LIVE separately).
	 *
	 * @param string            $from_ymd          Inclusive start day key.
	 * @param string            $to_ymd_inclusive  Inclusive end day key.
	 * @param DateTimeImmutable $now               Reference "now".
	 * @throws InvalidArgumentException When the range is invalid or too long.
	 */
	public static function custom( string $from_ymd, string $to_ymd_inclusive, DateTimeImmutable $now ): self {
		$from = UtcDay::from_key( $from_ymd );
		$to   = UtcDay::from_key( $to_ymd_inclusive );
		if ( $to < $from ) {
			throw new InvalidArgumentException( 'to_date must be on or after from_date.' );
		}
		$days = (int) $from->diff( $to )->days + 1;
		if ( $days > self::MAX_CUSTOM_DAYS ) {
			throw new InvalidArgumentException( 'Custom range exceeds ' . self::MAX_CUSTOM_DAYS . ' days.' );
		}
		// Cap exclusive end at start of today so LIVE day is not mixed into historical rollups silently.
		$today = UtcDay::start( $now );
		$end   = UtcDay::end_exclusive( $to );
		if ( $end > $today ) {
			$end = UtcDay::end_exclusive( $now );
		}

		return new self( self::PRESET_CUSTOM, $from, $end );
	}

	/**
	 * Preset identifier.
	 */
	public function preset(): string {
		return $this->preset;
	}

	/**
	 * Inclusive range start (UTC).
	 */
	public function from(): DateTimeImmutable {
		return $this->from;
	}

	/**
	 * Exclusive range end (UTC).
	 */
	public function to_exclusive(): DateTimeImmutable {
		return $this->to_exclusive;
	}

	/**
	 * Returns a copy with a different preset label.
	 *
	 * @param string $preset Preset identifier.
	 */
	private function with_preset( string $preset ): self {
		return new self( $preset, $this->from, $this->to_exclusive );
	}
}
