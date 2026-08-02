<?php
/**
 * Database-backed per-package line-quantity allocation repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

use MPCF\Domain\Repository\PackageItemRepository;
use MPCF\Domain\Shipping\PackageItem;

/**
 * The only class that reads or writes `mpcf_package_items`.
 */
final class WpdbPackageItemRepository implements PackageItemRepository {

	/**
	 * Every allocation on one package.
	 *
	 * @param int $package_id Package id.
	 */
	public function find_for_package( int $package_id ): array {
		global $wpdb;

		$table = Schema::table( Schema::PACKAGE_ITEMS );
		$rows  = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE package_id = %d ORDER BY id ASC", $package_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows ?? array() );
	}

	/**
	 * Every allocation across every package of one shipment.
	 *
	 * @param int $shipment_id Shipment id.
	 */
	public function find_for_shipment( int $shipment_id ): array {
		global $wpdb;

		$items_table    = Schema::table( Schema::PACKAGE_ITEMS );
		$packages_table = Schema::table( Schema::PACKAGES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are Schema-built, never user input.
		$sql = "SELECT pi.* FROM {$items_table} pi INNER JOIN {$packages_table} p ON p.id = pi.package_id WHERE p.shipment_id = %d ORDER BY pi.id ASC";

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sql's only dynamic fragments are the two Schema-built table names above, never user input; the %d placeholder is real and bound via $shipment_id.
			$wpdb->prepare( $sql, $shipment_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows ?? array() );
	}

	/**
	 * Inserts every given allocation.
	 *
	 * @param array<int, PackageItem> $items Allocations to insert.
	 */
	public function insert_all( array $items ): void {
		global $wpdb;

		$table = Schema::table( Schema::PACKAGE_ITEMS );

		foreach ( $items as $item ) {
			$wpdb->insert(
				$table,
				array(
					'package_id'          => $item->package_id(),
					'fulfillment_item_id' => $item->fulfillment_item_id(),
					'qty'                 => $item->qty(),
				),
				array( '%d', '%d', '%d' )
			);
		}
	}

	/**
	 * Deletes every allocation on a package.
	 *
	 * @param int $package_id Package id.
	 */
	public function delete_for_package( int $package_id ): void {
		global $wpdb;

		$table = Schema::table( Schema::PACKAGE_ITEMS );
		$wpdb->delete( $table, array( 'package_id' => $package_id ), array( '%d' ) );
	}

	/**
	 * Assembles an allocation from one `ARRAY_A` row.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 */
	private function hydrate( array $row ): PackageItem {
		return PackageItem::from_array(
			array(
				'id'                  => (int) $row['id'],
				'package_id'          => (int) $row['package_id'],
				'fulfillment_item_id' => (int) $row['fulfillment_item_id'],
				'qty'                 => (int) $row['qty'],
			)
		);
	}
}
