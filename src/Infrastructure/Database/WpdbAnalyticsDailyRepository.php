<?php
/**
 * Wpdb persistence for `mpcf_analytics_daily`.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

use MPCF\Domain\Repository\AnalyticsDailyRepository;
use MPCF\Engine\Analytics\DailyMetrics;

/**
 * Unique on (utc_date, warehouse_id). JSON payloads are deterministic
 * (json_encode of normalized arrays).
 */
final class WpdbAnalyticsDailyRepository implements AnalyticsDailyRepository {

	/**
	 * Finds a single day row.
	 *
	 * @param string $utc_date     UTC day key.
	 * @param int    $warehouse_id Warehouse scope.
	 */
	public function find( string $utc_date, int $warehouse_id ): ?DailyMetrics {
		global $wpdb;

		$table = Schema::table( Schema::ANALYTICS_DAILY );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE `utc_date` = %s AND warehouse_id = %d LIMIT 1",
				$utc_date,
				$warehouse_id
			),
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Finds rows for an inclusive UTC day range.
	 *
	 * @param string $from_utc_date         Inclusive start day key.
	 * @param string $to_utc_date_inclusive Inclusive end day key.
	 * @param int    $warehouse_id          Warehouse scope.
	 * @return list<DailyMetrics>
	 */
	public function find_range( string $from_utc_date, string $to_utc_date_inclusive, int $warehouse_id ): array {
		global $wpdb;

		$table = Schema::table( Schema::ANALYTICS_DAILY );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE warehouse_id = %d AND `utc_date` >= %s AND `utc_date` <= %s ORDER BY `utc_date` ASC",
				$warehouse_id,
				$from_utc_date,
				$to_utc_date_inclusive
			),
			ARRAY_A
		);

		$out = array();
		foreach ( $rows ?? array() as $row ) {
			$out[] = $this->hydrate( $row );
		}

		return $out;
	}

	/**
	 * Inserts or replaces a row (REBUILD / first ROLLUP materialization).
	 *
	 * @param DailyMetrics $metrics         Snapshot to persist.
	 * @param string       $computed_at_utc UTC timestamp string.
	 */
	public function upsert( DailyMetrics $metrics, string $computed_at_utc ): void {
		global $wpdb;

		$table   = Schema::table( Schema::ANALYTICS_DAILY );
		$now     = $computed_at_utc;
		$existing = $this->find( $metrics->utc_date(), $metrics->warehouse_id() );
		$max_id  = $metrics->source_event_max_id();

		if ( null === $existing ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (`utc_date`, warehouse_id, rollup_version, counters_json, durations_json, source_event_max_id, computed_at, created_at, updated_at)
					VALUES (%s, %d, %d, %s, %s, %s, %s, %s, %s)",
					$metrics->utc_date(),
					$metrics->warehouse_id(),
					$metrics->rollup_version(),
					wp_json_encode( $metrics->counters() ),
					wp_json_encode( $metrics->durations() ),
					null === $max_id ? null : (string) $max_id,
					$now,
					$now,
					$now
				)
			);
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET rollup_version = %d, counters_json = %s, durations_json = %s, source_event_max_id = %s, computed_at = %s, updated_at = %s
				WHERE `utc_date` = %s AND warehouse_id = %d",
				$metrics->rollup_version(),
				wp_json_encode( $metrics->counters() ),
				wp_json_encode( $metrics->durations() ),
				null === $max_id ? null : (string) $max_id,
				$now,
				$now,
				$metrics->utc_date(),
				$metrics->warehouse_id()
			)
		);
	}

	/**
	 * True when a row exists at current ROLLUP_VERSION.
	 *
	 * @param string $utc_date        UTC day key.
	 * @param int    $warehouse_id    Warehouse scope.
	 * @param int    $current_version Expected format version.
	 */
	public function has_current_version( string $utc_date, int $warehouse_id, int $current_version ): bool {
		$row = $this->find( $utc_date, $warehouse_id );

		return null !== $row && $row->rollup_version() === $current_version;
	}

	/**
	 * Count rows with rollup_version below `$version`.
	 *
	 * @param int $version Current format version threshold.
	 */
	public function count_obsolete( int $version ): int {
		global $wpdb;

		$table = Schema::table( Schema::ANALYTICS_DAILY );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE rollup_version < %d", $version ) );
	}

	/**
	 * Hydrates a database row into DailyMetrics.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	private function hydrate( array $row ): DailyMetrics {
		$counters  = json_decode( (string) ( $row['counters_json'] ?? '{}' ), true );
		$durations = json_decode( (string) ( $row['durations_json'] ?? '{}' ), true );

		return new DailyMetrics(
			substr( (string) $row['utc_date'], 0, 10 ),
			(int) $row['warehouse_id'],
			(int) $row['rollup_version'],
			is_array( $counters ) ? $counters : array(),
			is_array( $durations ) ? $durations : array(),
			isset( $row['source_event_max_id'] ) && '' !== $row['source_event_max_id'] && null !== $row['source_event_max_id']
				? (int) $row['source_event_max_id']
				: null
		);
	}
}
