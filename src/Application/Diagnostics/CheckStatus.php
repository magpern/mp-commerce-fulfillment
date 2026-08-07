<?php
/**
 * Check outcome status.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Diagnostics;

/**
 * Pass = healthy; warn = degraded; fail = incorrect / unsafe to ignore.
 */
final class CheckStatus {

	public const PASS = 'pass';
	public const WARN = 'warn';
	public const FAIL = 'fail';
}
