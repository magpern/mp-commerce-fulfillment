<?php
/**
 * Persistence contract for per-package line-quantity allocations.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Repository;

use MPCF\Domain\Shipping\PackageItem;

/**
 * Implemented in Infrastructure ({@see \MPCF\Infrastructure\Database\WpdbPackageItemRepository}),
 * confined there per invariant I7.
 */
interface PackageItemRepository {

	/**
	 * Every allocation on one package.
	 *
	 * @param int $package_id Package id.
	 * @return list<PackageItem>
	 */
	public function find_for_package( int $package_id ): array;

	/**
	 * Every allocation across every package of one shipment — how
	 * `ShippingService` finds what is already allocated when a new
	 * package is added.
	 *
	 * @param int $shipment_id Shipment id.
	 * @return list<PackageItem>
	 */
	public function find_for_shipment( int $shipment_id ): array;

	/**
	 * Inserts every given allocation.
	 *
	 * @param array<int, PackageItem> $items Allocations to insert.
	 */
	public function insert_all( array $items ): void;

	/**
	 * Deletes every allocation on a package (e.g. before re-allocating).
	 *
	 * @param int $package_id Package id.
	 */
	public function delete_for_package( int $package_id ): void;
}
