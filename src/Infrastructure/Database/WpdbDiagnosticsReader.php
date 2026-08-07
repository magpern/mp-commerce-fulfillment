<?php
/**
 * Read-only SQL probes for operational diagnostics (M10).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

/**
 * Confines diagnostic `$wpdb` reads to Infrastructure/Database (I7).
 */
final class WpdbDiagnosticsReader {

	/**
	 * Returns orphan and relationship inconsistency counts.
	 *
	 * @return array{
	 *   orphan_items:int,
	 *   orphan_shipments:int,
	 *   orphan_packages:int,
	 *   orphan_wave_members:int,
	 *   shipped_without_shipment:int
	 * }
	 */
	public function consistency_counts(): array {
		global $wpdb;

		$empty = array(
			'orphan_items'             => 0,
			'orphan_shipments'         => 0,
			'orphan_packages'          => 0,
			'orphan_wave_members'      => 0,
			'shipped_without_shipment' => 0,
		);

		if ( ! $this->table_exists( Schema::FULFILLMENTS ) ) {
			return $empty;
		}

		$f = Schema::table( Schema::FULFILLMENTS );
		$i = Schema::table( Schema::FULFILLMENT_ITEMS );
		$s = Schema::table( Schema::SHIPMENTS );
		$p = Schema::table( Schema::PACKAGES );
		$w = Schema::table( Schema::WAVES );
		$m = Schema::table( Schema::WAVE_MEMBERS );

		return array(
			'orphan_items'             => (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table names.
				"SELECT COUNT(*) FROM {$i} item LEFT JOIN {$f} f ON f.id = item.fulfillment_id WHERE f.id IS NULL"
			),
			'orphan_shipments'         => (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table names.
				"SELECT COUNT(*) FROM {$s} ship LEFT JOIN {$f} f ON f.id = ship.fulfillment_id WHERE f.id IS NULL"
			),
			'orphan_packages'          => (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table names.
				"SELECT COUNT(*) FROM {$p} pkg LEFT JOIN {$s} ship ON ship.id = pkg.shipment_id WHERE ship.id IS NULL"
			),
			'orphan_wave_members'      => (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table names.
				"SELECT COUNT(*) FROM {$m} wm LEFT JOIN {$w} w ON w.id = wm.wave_id LEFT JOIN {$f} f ON f.id = wm.fulfillment_id WHERE w.id IS NULL OR f.id IS NULL"
			),
			'shipped_without_shipment' => (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table names.
					"SELECT COUNT(*) FROM {$f} f LEFT JOIN {$s} s ON s.fulfillment_id = f.id WHERE f.state = %s AND s.id IS NULL",
					'shipped'
				)
			),
		);
	}

	/**
	 * Returns table counts and open-queue metrics.
	 *
	 * @return array{fulfillments:int,events:int,open_queue:int,oldest_open:?string}
	 */
	public function capacity_counts(): array {
		global $wpdb;

		if ( ! $this->table_exists( Schema::FULFILLMENTS ) ) {
			return array(
				'fulfillments' => 0,
				'events'       => 0,
				'open_queue'   => 0,
				'oldest_open'  => null,
			);
		}

		$f = Schema::table( Schema::FULFILLMENTS );
		$e = Schema::table( Schema::EVENTS );

		$open = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table names.
				"SELECT COUNT(*) FROM {$f} WHERE state NOT IN (%s,%s,%s)",
				'shipped',
				'cancelled',
				'completed'
			)
		);

		$oldest = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table names.
				"SELECT created_at FROM {$f} WHERE state NOT IN (%s,%s) ORDER BY created_at ASC LIMIT 1",
				'shipped',
				'cancelled'
			)
		);

		return array(
			'fulfillments' => (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
				"SELECT COUNT(*) FROM {$f}"
			),
			'events'       => (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
				"SELECT COUNT(*) FROM {$e}"
			),
			'open_queue'   => $open,
			'oldest_open'  => is_string( $oldest ) && '' !== $oldest ? $oldest : null,
		);
	}

	/**
	 * Pending/in-progress Action Scheduler actions for a hook.
	 *
	 * @param string $hook Action Scheduler hook name.
	 */
	public function action_scheduler_pending_count( string $hook ): int {
		global $wpdb;

		$table  = $wpdb->prefix . 'actionscheduler_actions';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( ! $exists ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WP core table name from prefix.
				"SELECT COUNT(*) FROM {$table} WHERE hook = %s AND status IN ('pending','in-progress')",
				$hook
			)
		);
	}

	/**
	 * Whether a schema table exists (unprefixed constant).
	 *
	 * @param string $unprefixed Schema table constant.
	 */
	public function table_exists( string $unprefixed ): bool {
		return $this->prefixed_table_exists( Schema::table( $unprefixed ) );
	}

	/**
	 * Whether a fully-prefixed table name exists.
	 *
	 * @param string $prefixed_table Full table name including prefix.
	 */
	public function prefixed_table_exists( string $prefixed_table ): bool {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefixed_table ) ) );

		return (string) $found === $prefixed_table;
	}

	/**
	 * Distinct fulfillment ids that have events, bounded.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return list<int>
	 */
	public function fulfillment_ids_with_events( int $limit ): array {
		global $wpdb;

		$table = Schema::table( Schema::EVENTS );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
				"SELECT DISTINCT fulfillment_id FROM {$table} WHERE fulfillment_id IS NOT NULL ORDER BY fulfillment_id ASC LIMIT %d",
				max( 1, $limit )
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}
}
