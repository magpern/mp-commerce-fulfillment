<?php
/**
 * Port for the current time, so Domain/Engine/Application code never calls
 * a real clock directly and stays deterministically testable.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

use DateTimeImmutable;

/**
 * A source of "now". The real implementation (Infrastructure) returns the
 * actual current time in UTC; tests substitute a fixed clock.
 */
interface Clock {

	/**
	 * The current moment, UTC.
	 */
	public function now(): DateTimeImmutable;
}
