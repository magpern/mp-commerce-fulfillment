<?php
/**
 * Test double clock returning a fixed moment.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Doubles;

use DateTimeImmutable;
use MPCF\Domain\Clock;

/**
 * A clock that always reports the same moment.
 */
final class FixedClock implements Clock {

	/**
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	public function __construct( DateTimeImmutable $now ) {
		$this->now = $now;
	}

	public function now(): DateTimeImmutable {
		return $this->now;
	}
}
