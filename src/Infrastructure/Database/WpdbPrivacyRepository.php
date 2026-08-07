<?php
/**
 * Privacy-oriented DB access for export/erase (M10).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

/**
 * Confines privacy `$wpdb` reads/writes to Infrastructure/Database (I7).
 */
final class WpdbPrivacyRepository {

	/**
	 * Loads fulfillment rows for the given order ids.
	 *
	 * @param array $order_ids Order ids.
	 * @return list<array<string, mixed>>
	 */
	public function fulfillments_for_orders( array $order_ids ): array {
		global $wpdb;
		if ( array() === $order_ids ) {
			return array();
		}

		$table        = Schema::table( Schema::FULFILLMENTS );
		$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table is Schema-built; placeholders built from count.
		$sql = $wpdb->prepare( "SELECT id, order_id, state, customer_name_snapshot, created_at FROM {$table} WHERE order_id IN ({$placeholders}) ORDER BY id ASC", ...$order_ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the prepared statement from above.
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns fulfillment ids for the given order ids.
	 *
	 * @param array $order_ids Order ids.
	 * @return list<int>
	 */
	public function fulfillment_ids_for_orders( array $order_ids ): array {
		$rows = $this->fulfillments_for_orders( $order_ids );

		return array_map( static fn( array $r ): int => (int) $r['id'], $rows );
	}

	/**
	 * Loads note rows for one fulfillment.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @return list<array<string, mixed>>
	 */
	public function notes_for_fulfillment( int $fulfillment_id ): array {
		global $wpdb;
		$table = Schema::table( Schema::NOTES );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
				"SELECT id, body FROM {$table} WHERE fulfillment_id = %d ORDER BY id ASC",
				$fulfillment_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Loads media metadata rows for one fulfillment.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @return list<array<string, mixed>>
	 */
	public function media_meta_for_fulfillment( int $fulfillment_id ): array {
		global $wpdb;
		$table = Schema::table( Schema::MEDIA );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
				"SELECT id, kind, sha256, bytes, deleted_at, purged_at, file_path, thumb_path FROM {$table} WHERE fulfillment_id = %d ORDER BY id ASC",
				$fulfillment_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Replaces customer_name_snapshot for one fulfillment.
	 *
	 * @param int    $fulfillment_id Fulfillment id.
	 * @param string $anon_name      Replacement name.
	 */
	public function anonymize_customer_name( int $fulfillment_id, string $anon_name ): bool {
		global $wpdb;
		$table = Schema::table( Schema::FULFILLMENTS );
		$n     = $wpdb->update(
			$table,
			array( 'customer_name_snapshot' => $anon_name ),
			array( 'id' => $fulfillment_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $n && $n > 0;
	}

	/**
	 * Replaces note bodies for one fulfillment.
	 *
	 * @param int    $fulfillment_id Fulfillment id.
	 * @param string $anon_body      Replacement body text.
	 */
	public function anonymize_notes( int $fulfillment_id, string $anon_body ): bool {
		global $wpdb;
		$table = Schema::table( Schema::NOTES );
		$n     = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
				"UPDATE {$table} SET body = %s WHERE fulfillment_id = %d AND body <> %s",
				$anon_body,
				$fulfillment_id,
				$anon_body
			)
		);

		return is_int( $n ) && $n > 0;
	}

	/**
	 * Marks one media row purged and clears path columns.
	 *
	 * @param array<string, mixed> $row Media row.
	 * @param string               $now Purged-at timestamp (UTC).
	 */
	public function mark_media_purged( array $row, string $now ): void {
		global $wpdb;
		$table = Schema::table( Schema::MEDIA );
		$wpdb->update(
			$table,
			array(
				'file_path'  => '',
				'thumb_path' => '',
				'deleted_at' => $row['deleted_at'] ?? $now,
				'purged_at'  => $now,
				'bytes'      => 0,
			),
			array( 'id' => (int) $row['id'] ),
			array( '%s', '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);
	}
}
