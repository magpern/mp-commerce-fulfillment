<?php
/**
 * Integration tests for the package repository against a real database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Infrastructure;

use DateTimeImmutable;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\Shipping\Package;
use MPCF\Domain\Shipping\PackageSpec;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\Database\WpdbPackageRepository;
use MPCF\Infrastructure\Database\WpdbShipmentRepository;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use WP_UnitTestCase;

/**
 * Integration tests for the package repository against a real database.
 */
final class WpdbPackageRepositoryTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;

	/**
	 * Repository under test.
	 *
	 * @var WpdbPackageRepository
	 */
	private WpdbPackageRepository $repository;

	/**
	 * Owning shipment's id, created fresh per test.
	 *
	 * @var int
	 */
	private int $shipment_id;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();
		$this->repository = new WpdbPackageRepository();

		$fulfillment_id    = ( new WpdbFulfillmentRepository() )->insert(
			Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'queued', '#1001', 'Jane Doe', 1, new DateTimeImmutable() )
		);
		$this->shipment_id = ( new WpdbShipmentRepository() )->insert( Shipment::create( $fulfillment_id, new DateTimeImmutable() ) );
	}

	public function test_insert_assigns_an_id_and_find_returns_an_equivalent_package(): void {
		$package = Package::create( $this->shipment_id, 1, new DateTimeImmutable( '2026-08-02 10:00:00' ) );
		$package->set_spec( PackageSpec::create( 1200, 300, 200, 100 ) );
		$package->set_tracking_number( 'COLLI-1' );

		$id = $this->repository->insert( $package );

		self::assertGreaterThan( 0, $id );

		$found = $this->repository->find( $id );

		self::assertNotNull( $found );
		self::assertSame( $this->shipment_id, $found->shipment_id() );
		self::assertSame( 1, $found->seq() );
		self::assertSame( 1200, $found->spec()->weight_grams() );
		self::assertSame( 300, $found->spec()->length_mm() );
		self::assertSame( 'COLLI-1', $found->tracking_number() );
	}

	public function test_next_seq_for_shipment_starts_at_one_and_increments(): void {
		self::assertSame( 1, $this->repository->next_seq_for_shipment( $this->shipment_id ) );

		$this->repository->insert( Package::create( $this->shipment_id, 1, new DateTimeImmutable() ) );
		self::assertSame( 2, $this->repository->next_seq_for_shipment( $this->shipment_id ) );

		$this->repository->insert( Package::create( $this->shipment_id, 2, new DateTimeImmutable() ) );
		self::assertSame( 3, $this->repository->next_seq_for_shipment( $this->shipment_id ) );
	}

	public function test_find_for_shipment_returns_packages_in_seq_order(): void {
		$second = $this->repository->insert( Package::create( $this->shipment_id, 2, new DateTimeImmutable() ) );
		$first  = $this->repository->insert( Package::create( $this->shipment_id, 1, new DateTimeImmutable() ) );

		$packages = $this->repository->find_for_shipment( $this->shipment_id );

		self::assertCount( 2, $packages );
		self::assertSame( $first, $packages[0]->id() );
		self::assertSame( $second, $packages[1]->id() );
	}

	public function test_save_persists_a_spec_update(): void {
		$id      = $this->repository->insert( Package::create( $this->shipment_id, 1, new DateTimeImmutable() ) );
		$package = $this->repository->find( $id );

		$package->set_spec( PackageSpec::create( 500 ) );
		$this->repository->save( $package );

		self::assertSame( 500, $this->repository->find( $id )->spec()->weight_grams() );
	}

	public function test_delete_removes_the_package(): void {
		$id = $this->repository->insert( Package::create( $this->shipment_id, 1, new DateTimeImmutable() ) );

		$this->repository->delete( $id );

		self::assertNull( $this->repository->find( $id ) );
	}
}
