<?php
/**
 * UTC calendar-day helpers for analytics rollups.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\Analytics;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Rollups are keyed by UTC calendar day only.
 */
final class UtcDay {

	/**
	 * UTC timezone used for all rollup day boundaries.
	 */
	public static function timezone(): DateTimeZone {
		return new DateTimeZone( 'UTC' );
	}

	/**
	 * Formats a moment as a UTC `Y-m-d` day key.
	 *
	 * @param DateTimeImmutable $moment Instant to key.
	 */
	public static function key( DateTimeImmutable $moment ): string {
		return $moment->setTimezone( self::timezone() )->format( 'Y-m-d' );
	}

	/**
	 * Inclusive start of the UTC calendar day for `$moment`.
	 *
	 * @param DateTimeImmutable $moment Instant within the day.
	 */
	public static function start( DateTimeImmutable $moment ): DateTimeImmutable {
		return $moment->setTimezone( self::timezone() )->setTime( 0, 0, 0 );
	}

	/**
	 * Exclusive end (start of next UTC day).
	 *
	 * @param DateTimeImmutable $moment Instant within the day.
	 */
	public static function end_exclusive( DateTimeImmutable $moment ): DateTimeImmutable {
		return self::start( $moment )->modify( '+1 day' );
	}

	/**
	 * Yesterday's UTC day start relative to `$now`.
	 *
	 * @param DateTimeImmutable $now Reference "now".
	 */
	public static function yesterday_start( DateTimeImmutable $now ): DateTimeImmutable {
		return self::start( $now )->modify( '-1 day' );
	}

	/**
	 * True when `$moment` falls on the current UTC calendar day of `$now`.
	 *
	 * @param DateTimeImmutable $moment Instant to test.
	 * @param DateTimeImmutable $now    Reference "now".
	 */
	public static function is_today( DateTimeImmutable $moment, DateTimeImmutable $now ): bool {
		return self::key( $moment ) === self::key( $now );
	}

	/**
	 * Parses `Y-m-d` as UTC midnight.
	 *
	 * @param string $ymd UTC day key.
	 * @throws \InvalidArgumentException When `$ymd` is not a valid `Y-m-d`.
	 */
	public static function from_key( string $ymd ): DateTimeImmutable {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, self::timezone() );
		if ( false === $dt ) {
			throw new \InvalidArgumentException( 'Invalid UTC date key: ' . $ymd );
		}

		return $dt;
	}
}
