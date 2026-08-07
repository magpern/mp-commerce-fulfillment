<?php
/**
 * Rollup payload format version.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\Analytics;

/**
 * Stored on every `mpcf_analytics_daily` row. Bump when percentile/layout
 * changes; REBUILD detects `rollup_version < CURRENT`.
 */
final class RollupVersion {

	public const CURRENT = 1;
}
