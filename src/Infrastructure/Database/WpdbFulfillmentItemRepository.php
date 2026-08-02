<?php
/**
 * Database-backed fulfillment line item repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Repository\FulfillmentItemRepository;

/**
 * The only class that reads or writes `mpcf_fulfillment_items`.
 */
final class WpdbFulfillmentItemRepository implements FulfillmentItemRepository {

	/**
	 * Every line item belonging to one fulfillment.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function find_for_fulfillment( int $fulfillment_id ): array {
		global $wpdb;

		$table = Schema::table( Schema::FULFILLMENT_ITEMS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE fulfillment_id = %d ORDER BY id ASC", $fulfillment_id ), ARRAY_A );

		return array_map( array( $this, 'hydrate' ), $rows ?? array() );
	}

	/**
	 * Inserts every item for a fulfillment at intake time.
	 *
	 * @param array<int, FulfillmentItem> $items Items to insert.
	 */
	public function insert_all( array $items ): void {
		global $wpdb;

		$table = Schema::table( Schema::FULFILLMENT_ITEMS );

		foreach ( $items as $item ) {
			$wpdb->insert(
				$table,
				array(
					'fulfillment_id'    => $item->fulfillment_id(),
					'order_item_id'     => $item->order_item_id(),
					'product_id'        => $item->product_id(),
					'variation_id'      => $item->variation_id(),
					'sku_snapshot'      => $item->sku_snapshot(),
					'name_snapshot'     => $item->name_snapshot(),
					'qty_ordered'       => $item->qty_ordered(),
					'qty_picked'        => $item->qty_picked(),
					'qty_packed'        => $item->qty_packed(),
					'location_snapshot' => $item->location_snapshot(),
				),
				array( '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s' )
			);
		}
	}

	/**
	 * Persists a mutation to an existing item (picked/packed quantities).
	 *
	 * @param FulfillmentItem $item Item to persist.
	 */
	public function save( FulfillmentItem $item ): void {
		global $wpdb;

		$table = Schema::table( Schema::FULFILLMENT_ITEMS );

		$wpdb->update(
			$table,
			array(
				'qty_picked'        => $item->qty_picked(),
				'qty_packed'        => $item->qty_packed(),
				'location_snapshot' => $item->location_snapshot(),
			),
			array( 'id' => $item->id() ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Assembles a line item from one `ARRAY_A` row.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 */
	private function hydrate( array $row ): FulfillmentItem {
		return FulfillmentItem::from_array(
			array(
				'id'                => (int) $row['id'],
				'fulfillment_id'    => (int) $row['fulfillment_id'],
				'order_item_id'     => (int) $row['order_item_id'],
				'product_id'        => (int) $row['product_id'],
				'variation_id'      => (int) $row['variation_id'],
				'sku_snapshot'      => (string) $row['sku_snapshot'],
				'name_snapshot'     => (string) $row['name_snapshot'],
				'qty_ordered'       => (int) $row['qty_ordered'],
				'qty_picked'        => (int) $row['qty_picked'],
				'qty_packed'        => (int) $row['qty_packed'],
				'location_snapshot' => $row['location_snapshot'],
			)
		);
	}
}
