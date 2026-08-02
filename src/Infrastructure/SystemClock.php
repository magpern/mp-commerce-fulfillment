<?php
/**
 * The real clock.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use MPCF\Domain\Clock;

/**
 * Returns the actual current time, always UTC — the DB columns it feeds
 * are UTC `DATETIME`, and this is the one place that fact is encoded.
 */
final class SystemClock implements Clock {

	/**
	 * The current moment, UTC.
	 */
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}
}
