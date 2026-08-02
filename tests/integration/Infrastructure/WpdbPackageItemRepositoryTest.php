<?php
/**
 * Integration tests for the per-package line-quantity allocation
 * repository against a real database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Infrastructure;

use DateTimeImmutable;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\Shipping\Package;
use MPCF\Domain\Shipping\PackageItem;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\Database\WpdbPackageItemRepository;
use MPCF\Infrastructure\Database\WpdbPackageRepository;
use MPCF\Infrastructure\Database\WpdbShipmentRepository;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use WP_UnitTestCase;

/**
 * Integration tests for the per-package line-quantity allocation
 * repository against a real database.
 */
final class WpdbPackageItemRepositoryTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;

	/**
	 * Repository under test.
	 *
	 * @var WpdbPackageItemRepository
	 */
	private WpdbPackageItemRepository $repository;

	/**
	 * A package's id, created fresh per test.
	 *
	 * @var int
	 */
	private int $package_id;

	/**
	 * The owning shipment's id.
	 *
	 * @var int
	 */
	private int $shipment_id;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();
		$this->repository = new WpdbPackageItemRepository();

		$fulfillment_id    = ( new WpdbFulfillmentRepository() )->insert(
			Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'queued', '#1001', 'Jane Doe', 1, new DateTimeImmutable() )
		);
		$this->shipment_id = ( new WpdbShipmentRepository() )->insert( Shipment::create( $fulfillment_id, new DateTimeImmutable() ) );
		$this->package_id  = ( new WpdbPackageRepository() )->insert( Package::create( $this->shipment_id, 1, new DateTimeImmutable() ) );
	}

	public function test_insert_all_and_find_for_package(): void {
		$this->repository->insert_all(
			array(
				PackageItem::create( $this->package_id, 900, 2 ),
				PackageItem::create( $this->package_id, 901, 1 ),
			)
		);

		$items = $this->repository->find_for_package( $this->package_id );

		self::assertCount( 2, $items );
		self::assertSame( 900, $items[0]->fulfillment_item_id() );
		self::assertSame( 2, $items[0]->qty() );
		self::assertSame( 901, $items[1]->fulfillment_item_id() );
	}

	public function test_find_for_shipment_joins_across_every_package(): void {
		$second_package_id = ( new WpdbPackageRepository() )->insert( Package::create( $this->shipment_id, 2, new DateTimeImmutable() ) );

		$this->repository->insert_all( array( PackageItem::create( $this->package_id, 900, 1 ) ) );
		$this->repository->insert_all( array( PackageItem::create( $second_package_id, 901, 1 ) ) );

		$items = $this->repository->find_for_shipment( $this->shipment_id );

		self::assertCount( 2, $items );
	}

	public function test_delete_for_package_removes_only_that_packages_allocations(): void {
		$second_package_id = ( new WpdbPackageRepository() )->insert( Package::create( $this->shipment_id, 2, new DateTimeImmutable() ) );

		$this->repository->insert_all( array( PackageItem::create( $this->package_id, 900, 1 ) ) );
		$this->repository->insert_all( array( PackageItem::create( $second_package_id, 901, 1 ) ) );

		$this->repository->delete_for_package( $this->package_id );

		self::assertSame( array(), $this->repository->find_for_package( $this->package_id ) );
		self::assertCount( 1, $this->repository->find_for_package( $second_package_id ) );
	}
}
