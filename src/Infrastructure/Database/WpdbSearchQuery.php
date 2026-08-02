<?php
/**
 * The database-backed Queue search implementation.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

use MPCF\Domain\SearchQuery;
use MPCF\Domain\SearchTermClassifier;

/**
 * SearchQuery v1 (Architecture Plan §9.3/D22): classifies the term via
 * {@see SearchTermClassifier} (pure Domain logic) and runs exactly one
 * targeted, indexed lookup per classification — never an unindexed
 * `LIKE '%…%'` scan. Name/SKU lookups use a trailing-wildcard `LIKE
 * 'term%'` (a leading-anchored prefix scan, which a B-tree index on that
 * column can satisfy as a range scan), never a leading wildcard.
 */
final class WpdbSearchQuery implements SearchQuery {

	/**
	 * Every fulfillment id matching a search term.
	 *
	 * @param string $term Raw search term.
	 * @return list<int>
	 */
	public function search( string $term ): array {
		switch ( SearchTermClassifier::classify( $term ) ) {
			case SearchTermClassifier::NUMERIC:
				return $this->search_by_identifier( (int) $term );

			case SearchTermClassifier::SKU:
				return $this->search_by_sku( $term );

			default:
				return $this->search_by_customer_name( $term );
		}
	}

	/**
	 * Numeric terms match the fulfillment's own id or its origin order id —
	 * both indexed (`PRIMARY KEY`, `KEY order_id`).
	 *
	 * @param int $identifier Numeric term.
	 * @return list<int>
	 */
	private function search_by_identifier( int $identifier ): array {
		global $wpdb;

		$table = Schema::table( Schema::FULFILLMENTS );
		$ids   = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
			$wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d OR order_id = %d", $identifier, $identifier )
		);

		return array_map( 'intval', $ids ?? array() );
	}

	/**
	 * SKU-shaped terms match `mpcf_fulfillment_items.sku_snapshot` via the
	 * index the Migrator's step 3 adds, unioned to their owning fulfillment
	 * ids.
	 *
	 * @param string $term SKU-shaped term.
	 * @return list<int>
	 */
	private function search_by_sku( string $term ): array {
		global $wpdb;

		$table = Schema::table( Schema::FULFILLMENT_ITEMS );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
				"SELECT DISTINCT fulfillment_id FROM {$table} WHERE sku_snapshot LIKE %s",
				$wpdb->esc_like( $term ) . '%'
			)
		);

		return array_map( 'intval', $ids ?? array() );
	}

	/**
	 * Every other term matches `mpcf_fulfillments.customer_name_snapshot`
	 * via the index the Migrator's step 3 adds.
	 *
	 * @param string $term Free-text term.
	 * @return list<int>
	 */
	private function search_by_customer_name( string $term ): array {
		global $wpdb;

		$table = Schema::table( Schema::FULFILLMENTS );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
				"SELECT id FROM {$table} WHERE customer_name_snapshot LIKE %s",
				$wpdb->esc_like( $term ) . '%'
			)
		);

		return array_map( 'intval', $ids ?? array() );
	}
}
