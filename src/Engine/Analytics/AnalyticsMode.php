<?php
/**
 * AnalyticsEngine execution modes (Part XI — exactly three).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\Analytics;

/**
 * Binding: every calculation path goes through LIVE, ROLLUP, or REBUILD.
 */
final class AnalyticsMode {

	public const LIVE = 'live';

	public const ROLLUP = 'rollup';

	public const REBUILD = 'rebuild';

	/**
	 * All valid mode identifiers.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::LIVE, self::ROLLUP, self::REBUILD );
	}

	/**
	 * Whether `$mode` is one of the three allowed modes.
	 *
	 * @param string $mode Candidate mode.
	 */
	public static function is_valid( string $mode ): bool {
		return in_array( $mode, self::all(), true );
	}
}
