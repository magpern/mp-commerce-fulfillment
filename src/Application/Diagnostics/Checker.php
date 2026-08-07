<?php
/**
 * Single diagnostic checker port.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Diagnostics;

/**
 * Produces one or more CheckResult values. Must be read-only.
 */
interface Checker {

	/**
	 * Stable checker id (prefix for result ids).
	 */
	public function id(): string;

	/**
	 * Primary category this checker covers.
	 */
	public function category(): string;

	/**
	 * Runs the check. Must not mutate state.
	 *
	 * @return list<CheckResult>
	 */
	public function run(): array;
}
