<?php
/**
 * Integration tests for the shipment repository against a real database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Infrastructure;

use DateTimeImmutable;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Domain\Shipping\TrackingReference;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\Database\WpdbShipmentRepository;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use WP_UnitTestCase;

/**
 * Integration tests for the shipment repository against a real database.
 */
final class WpdbShipmentRepositoryTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;

	/**
	 * Repository under test.
	 *
	 * @var WpdbShipmentRepository
	 */
	private WpdbShipmentRepository $repository;

	/**
	 * Owning fulfillment's id, created fresh per test.
	 *
	 * @var int
	 */
	private int $fulfillment_id;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();
		$this->repository     = new WpdbShipmentRepository();
		$this->fulfillment_id = ( new WpdbFulfillmentRepository() )->insert(
			Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'queued', '#1001', 'Jane Doe', 1, new DateTimeImmutable() )
		);
	}

	public function test_insert_assigns_an_id_and_find_returns_an_equivalent_shipment(): void {
		$shipment = Shipment::create( $this->fulfillment_id, new DateTimeImmutable( '2026-08-02 10:00:00' ) );
		$shipment->set_carrier( 'postnord', 'MyPack' );
		$shipment->set_tracking( TrackingReference::create( 'ABC123', 'https://track.example/ABC123' ) );

		$id = $this->repository->insert( $shipment );

		self::assertGreaterThan( 0, $id );

		$found = $this->repository->find( $id );

		self::assertNotNull( $found );
		self::assertSame( $this->fulfillment_id, $found->fulfillment_id() );
		self::assertSame( 'postnord', $found->carrier_id() );
		self::assertSame( 'MyPack', $found->service() );
		self::assertSame( 'ABC123', $found->tracking()->number() );
		self::assertSame( 'https://track.example/ABC123', $found->tracking()->url() );
		self::assertSame( Shipment::STATUS_PENDING, $found->status() );
	}

	public function test_find_returns_null_for_an_unknown_id(): void {
		self::assertNull( $this->repository->find( 999999 ) );
	}

	public function test_find_for_fulfillment_returns_every_shipment_oldest_first(): void {
		$first  = $this->repository->insert( Shipment::create( $this->fulfillment_id, new DateTimeImmutable( '2026-08-01 00:00:00' ) ) );
		$second = $this->repository->insert( Shipment::create( $this->fulfillment_id, new DateTimeImmutable( '2026-08-02 00:00:00' ) ) );

		$shipments = $this->repository->find_for_fulfillment( $this->fulfillment_id );

		self::assertCount( 2, $shipments );
		self::assertSame( $first, $shipments[0]->id() );
		self::assertSame( $second, $shipments[1]->id() );
	}

	public function test_save_persists_status_and_timestamps(): void {
		$id       = $this->repository->insert( Shipment::create( $this->fulfillment_id, new DateTimeImmutable() ) );
		$shipment = $this->repository->find( $id );

		$shipped_at = new DateTimeImmutable( '2026-08-02 12:00:00' );
		$shipment->mark_shipped( $shipped_at );
		$this->repository->save( $shipment );

		$reloaded = $this->repository->find( $id );

		self::assertSame( Shipment::STATUS_SHIPPED, $reloaded->status() );
		self::assertSame( $shipped_at->format( 'Y-m-d H:i:s' ), $reloaded->shipped_at()->format( 'Y-m-d H:i:s' ) );
	}

	public function test_delete_removes_the_shipment(): void {
		$id = $this->repository->insert( Shipment::create( $this->fulfillment_id, new DateTimeImmutable() ) );

		$this->repository->delete( $id );

		self::assertNull( $this->repository->find( $id ) );
	}
}
