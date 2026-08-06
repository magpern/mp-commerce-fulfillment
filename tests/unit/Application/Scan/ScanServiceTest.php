<?php
/**
 * Unit tests for ScanService pick/pack/undo orchestration.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Scan;

use DateTimeImmutable;
use MPCF\Application\EventDispatcher;
use MPCF\Application\PackingService;
use MPCF\Application\Scan\ScanService;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Scan\ScanResolver;
use MPCF\Domain\Shipping\Package;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Tests\Unit\Application\Doubles\FixedClock;
use MPCF\Tests\Unit\Application\Doubles\InMemoryEventRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentItemRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryPackageRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryScanCorrectionStore;
use MPCF\Tests\Unit\Application\Doubles\InMemoryShipmentRepository;
use PHPUnit\Framework\TestCase;

/**
 * Part IX.10 ScanService behaviour.
 */
final class ScanServiceTest extends TestCase {

	/**
	 * @var InMemoryFulfillmentRepository
	 */
	private InMemoryFulfillmentRepository $fulfillments;

	/**
	 * @var InMemoryFulfillmentItemRepository
	 */
	private InMemoryFulfillmentItemRepository $items;

	/**
	 * @var InMemoryEventRepository
	 */
	private InMemoryEventRepository $events;

	/**
	 * @var InMemoryScanCorrectionStore
	 */
	private InMemoryScanCorrectionStore $corrections;

	/**
	 * @var ScanService
	 */
	private ScanService $service;

	/**
	 * @var int
	 */
	private int $fulfillment_id;

	/**
	 * @var int
	 */
	private int $item_id;

	protected function setUp(): void {
		$this->fulfillments = new InMemoryFulfillmentRepository();
		$this->items        = new InMemoryFulfillmentItemRepository();
		$this->events       = new InMemoryEventRepository();
		$this->corrections  = new InMemoryScanCorrectionStore();
		$packages           = new InMemoryPackageRepository();
		$shipments          = new InMemoryShipmentRepository();
		$clock              = new FixedClock( new DateTimeImmutable( '2026-08-04 10:00:00' ) );
		$dispatcher         = new EventDispatcher();
		$packing            = new PackingService( $this->fulfillments, $this->items, $this->events, $dispatcher, $clock );

		$this->service = new ScanService(
			$this->fulfillments,
			$this->items,
			$packing,
			new ScanResolver(),
			$packages,
			$shipments,
			$this->corrections,
			$this->events,
			$dispatcher,
			$clock
		);

		$this->fulfillment_id = $this->fulfillments->insert(
			Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'picking', '#1001', 'Jane', 1, new DateTimeImmutable() )
		);
		$this->items->insert_all(
			array(
				FulfillmentItem::intake( $this->fulfillment_id, 501, 900, 0, 'SKU-1', 'Widget', 2 ),
			)
		);
		$this->item_id = (int) $this->items->find_for_fulfillment( $this->fulfillment_id )[0]->id();
	}

	public function test_scan_pick_increments_and_audits(): void {
		$outcome = $this->service->scan_pick( $this->fulfillment_id, 1, 'SKU-1', Actor::user( 7, 'Op' ) );

		self::assertTrue( $outcome->is_success() );
		self::assertSame( 1, $outcome->item()->qty_picked() );
		self::assertSame( 2, $outcome->version() );

		$types = array_column( $this->events->timeline_for_fulfillment( $this->fulfillment_id ), 'event_type' );
		self::assertContains( 'items.picked', $types );
		self::assertContains( 'scan.item_picked', $types );
	}

	public function test_over_scan_does_not_mutate(): void {
		$this->service->scan_pick( $this->fulfillment_id, 1, 'SKU-1', Actor::user( 7, 'Op' ) );
		$this->service->scan_pick( $this->fulfillment_id, 2, 'SKU-1', Actor::user( 7, 'Op' ) );
		$outcome = $this->service->scan_pick( $this->fulfillment_id, 3, 'SKU-1', Actor::user( 7, 'Op' ) );

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'over_scan', $outcome->code() );
		self::assertSame( 2, $this->items->find_for_fulfillment( $this->fulfillment_id )[0]->qty_picked() );
	}

	public function test_wrong_stage_rejected(): void {
		$data          = $this->fulfillments->find( $this->fulfillment_id )->to_array();
		$data['state'] = 'queued';
		$this->fulfillments->save( Fulfillment::from_array( $data ) );

		$outcome = $this->service->scan_pick( $this->fulfillment_id, 1, 'SKU-1', Actor::user( 7, 'Op' ) );

		self::assertSame( 'wrong_stage', $outcome->code() );
	}

	public function test_stale_version_rejected(): void {
		$outcome = $this->service->scan_pick( $this->fulfillment_id, 99, 'SKU-1', Actor::user( 7, 'Op' ) );

		self::assertSame( 'version_conflict', $outcome->code() );
	}

	public function test_pack_requires_picked_quantity(): void {
		$data          = $this->fulfillments->find( $this->fulfillment_id )->to_array();
		$data['state'] = 'packing';
		$this->fulfillments->save( Fulfillment::from_array( $data ) );

		$outcome = $this->service->scan_pack( $this->fulfillment_id, 1, 'SKU-1', Actor::user( 7, 'Op' ) );

		self::assertSame( 'not_yet_picked', $outcome->code() );
	}

	public function test_pack_increments_after_pick(): void {
		$this->service->scan_pick( $this->fulfillment_id, 1, 'SKU-1', Actor::user( 7, 'Op' ) );
		$data          = $this->fulfillments->find( $this->fulfillment_id )->to_array();
		$data['state'] = 'packing';
		$this->fulfillments->save( Fulfillment::from_array( $data ) );
		$version = $this->fulfillments->find( $this->fulfillment_id )->version();

		$outcome = $this->service->scan_pack( $this->fulfillment_id, $version, 'SKU-1', Actor::user( 7, 'Op' ) );

		self::assertTrue( $outcome->is_success() );
		self::assertSame( 1, $outcome->item()->qty_packed() );
	}

	public function test_undo_last_scan_decrements(): void {
		$this->service->scan_pick( $this->fulfillment_id, 1, 'SKU-1', Actor::user( 7, 'Op' ) );
		$outcome = $this->service->undo_last_scan( $this->fulfillment_id, 2, Actor::user( 7, 'Op' ) );

		self::assertTrue( $outcome->is_success() );
		self::assertSame( 0, $outcome->item()->qty_picked() );

		$types = array_column( $this->events->timeline_for_fulfillment( $this->fulfillment_id ), 'event_type' );
		self::assertContains( 'scan.corrected', $types );
	}

	public function test_unknown_barcode_rejected(): void {
		$outcome = $this->service->scan_pick( $this->fulfillment_id, 1, 'NOPE', Actor::user( 7, 'Op' ) );

		self::assertSame( 'unknown_barcode', $outcome->code() );
	}

	public function test_package_switch_validates_ownership(): void {
		$data          = $this->fulfillments->find( $this->fulfillment_id )->to_array();
		$data['state'] = 'packing';
		$this->fulfillments->save( Fulfillment::from_array( $data ) );

		$packages  = new InMemoryPackageRepository();
		$shipments = new InMemoryShipmentRepository();
		$clock     = new FixedClock( new DateTimeImmutable( '2026-08-04 10:00:00' ) );
		$packing   = new PackingService( $this->fulfillments, $this->items, $this->events, new EventDispatcher(), $clock );
		$service   = new ScanService(
			$this->fulfillments,
			$this->items,
			$packing,
			new ScanResolver(),
			$packages,
			$shipments,
			$this->corrections,
			$this->events,
			new EventDispatcher(),
			$clock
		);

		$shipment_id = $shipments->insert( Shipment::create( $this->fulfillment_id, new DateTimeImmutable() ) );
		$package_id  = $packages->insert( Package::create( $shipment_id, 1, new DateTimeImmutable() ) );

		$ok = $service->scan_pack( $this->fulfillment_id, 1, 'MPCF:P:' . $package_id, Actor::user( 7, 'Op' ) );
		self::assertSame( 'package_switched', $ok->code() );
		self::assertSame( $package_id, $ok->active_package_id() );

		$bad = $service->scan_pack( $this->fulfillment_id, 1, 'MPCF:P:999', Actor::user( 7, 'Op' ) );
		self::assertSame( 'package_not_found', $bad->code() );
	}
}
