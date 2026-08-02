<?php
/**
 * In-memory test double for the per-package line-quantity allocation
 * repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Doubles;

use MPCF\Domain\Repository\PackageItemRepository;
use MPCF\Domain\Shipping\PackageItem;

/**
 * In-memory package-item allocation store; no real database involved.
 */
final class InMemoryPackageItemRepository implements PackageItemRepository {

	/**
	 * @var array<int, array<int, PackageItem>>
	 */
	private array $by_package = array();

	public function find_for_package( int $package_id ): array {
		return $this->by_package[ $package_id ] ?? array();
	}

	public function find_for_shipment( int $shipment_id ): array {
		// Unused by ShippingService's own tests (which query per-package);
		// left unimplemented rather than faked incorrectly, matching the
		// house convention that a double only fakes what it is exercised
		// against.
		return array();
	}

	public function insert_all( array $items ): void {
		foreach ( $items as $item ) {
			$this->by_package[ $item->package_id() ][] = $item;
		}
	}

	public function delete_for_package( int $package_id ): void {
		unset( $this->by_package[ $package_id ] );
	}
}
