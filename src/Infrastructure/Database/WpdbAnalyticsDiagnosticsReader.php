<?php
/**
 * Read-only diagnostic list rows for Analytics (M9-D).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

use DateTimeImmutable;
use MPCF\Domain\Repository\AnalyticsDiagnosticsSource;
use MPCF\Engine\Analytics\QueueAgeingBuckets;

/**
 * Bounded lists only — no mutations.
 */
final class WpdbAnalyticsDiagnosticsReader implements AnalyticsDiagnosticsSource {

	/**
	 * Open fulfillments older than 4h in current state.
	 *
	 * @param array             $open_states  Open workflow state keys.
	 * @param int               $warehouse_id Warehouse scope.
	 * @param DateTimeImmutable $now          Reference "now".
	 * @param int               $limit        Max rows.
	 * @return list<array<string, mixed>>
	 */
	public function slow_fulfillments( array $open_states, int $warehouse_id, DateTimeImmutable $now, int $limit = 25 ): array {
		global $wpdb;

		if ( array() === $open_states ) {
			return array();
		}

		$table  = Schema::table( Schema::FULFILLMENTS );
		$in     = implode( ',', array_fill( 0, count( $open_states ), '%s' ) );
		$cutoff = $now->modify( '-4 hours' )->format( 'Y-m-d H:i:s' );
		$sql    = "SELECT id, order_number_snapshot, state, state_entered_at, warehouse_id
			FROM {$table}
			WHERE warehouse_id = %d AND state IN ({$in}) AND state_entered_at <= %s
			ORDER BY state_entered_at ASC LIMIT %d";
		$args   = array_merge( array( $warehouse_id ), $open_states, array( $cutoff, $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders built from counted states; table from Schema.
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$now_ts = $now->getTimestamp();
		$out    = array();

		foreach ( $rows ?? array() as $row ) {
			$ts    = strtotime( (string) $row['state_entered_at'] . ' UTC' );
			$age   = false === $ts ? 0 : max( 0, $now_ts - $ts );
			$out[] = array(
				'entity'      => 'fulfillment',
				'id'          => (int) $row['id'],
				'label'       => (string) $row['order_number_snapshot'],
				'state'       => (string) $row['state'],
				'age_seconds' => $age,
				'age_bucket'  => QueueAgeingBuckets::bucket_for_age( $age ),
				'reason'      => 'slow_in_state',
			);
		}

		return $out;
	}

	/**
	 * Active/paused waves older than threshold.
	 *
	 * @param int               $warehouse_id Warehouse scope.
	 * @param DateTimeImmutable $now          Reference "now".
	 * @param int               $limit        Max rows.
	 * @return list<array<string, mixed>>
	 */
	public function stalled_waves( int $warehouse_id, DateTimeImmutable $now, int $limit = 25 ): array {
		global $wpdb;

		$table  = Schema::table( Schema::WAVES );
		$cutoff = $now->modify( '-2 hours' )->format( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, state, activated_at, updated_at FROM {$table} WHERE warehouse_id = %d AND state IN ('active','paused') AND updated_at <= %s ORDER BY updated_at ASC LIMIT %d", $warehouse_id, $cutoff, $limit ), ARRAY_A );
		$now_ts = $now->getTimestamp();
		$out    = array();

		foreach ( $rows ?? array() as $row ) {
			$ts    = strtotime( (string) $row['updated_at'] . ' UTC' );
			$age   = false === $ts ? 0 : max( 0, $now_ts - $ts );
			$out[] = array(
				'entity'      => 'wave',
				'id'          => (int) $row['id'],
				'label'       => (string) ( '' !== $row['title'] ? $row['title'] : ( 'Wave #' . $row['id'] ) ),
				'state'       => (string) $row['state'],
				'age_seconds' => $age,
				'reason'      => 'paused' === $row['state'] ? 'excessive_pause' : 'stalled_active',
			);
		}

		return $out;
	}
}
