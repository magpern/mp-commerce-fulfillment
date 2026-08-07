<?php
/**
 * Persistence contract for `mpcf_analytics_daily`.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Repository;

use MPCF\Engine\Analytics\DailyMetrics;

/**
 * Rollup row store. Writes only from ROLLUP/REBUILD paths.
 */
interface AnalyticsDailyRepository {

	/**
	 * Finds a single day row.
	 *
	 * @param string $utc_date     UTC day key.
	 * @param int    $warehouse_id Warehouse scope.
	 */
	public function find( string $utc_date, int $warehouse_id ): ?DailyMetrics;

	/**
	 * Finds rows for an inclusive UTC day range.
	 *
	 * @param string $from_utc_date         Inclusive start day key.
	 * @param string $to_utc_date_inclusive Inclusive end day key.
	 * @param int    $warehouse_id          Warehouse scope.
	 * @return list<DailyMetrics>
	 */
	public function find_range( string $from_utc_date, string $to_utc_date_inclusive, int $warehouse_id ): array;

	/**
	 * Inserts or replaces a row (REBUILD / first ROLLUP materialization).
	 *
	 * @param DailyMetrics $metrics         Snapshot to persist.
	 * @param string       $computed_at_utc UTC timestamp string.
	 */
	public function upsert( DailyMetrics $metrics, string $computed_at_utc ): void;

	/**
	 * True when a row exists at current ROLLUP_VERSION.
	 *
	 * @param string $utc_date         UTC day key.
	 * @param int    $warehouse_id     Warehouse scope.
	 * @param int    $current_version  Expected format version.
	 */
	public function has_current_version( string $utc_date, int $warehouse_id, int $current_version ): bool;

	/**
	 * Count rows with rollup_version below `$version`.
	 *
	 * @param int $version Current format version threshold.
	 */
	public function count_obsolete( int $version ): int;
}
