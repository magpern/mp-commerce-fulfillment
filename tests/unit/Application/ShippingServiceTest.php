<?php
/**
 * Tests for the shipment/package lifecycle service.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application;

use DateTimeImmutable;
use MPCF\Application\EventDispatcher;
use MPCF\Application\ShippingService;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Tests\Unit\Application\Doubles\FixedClock;
use MPCF\Tests\Unit\Application\Doubles\InMemoryEventRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentItemRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryPackageItemRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryPackageRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryShipmentRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the shipment/package lifecycle service.
 */
final class ShippingServiceTest extends TestCase {

	/**
	 * @var InMemoryFulfillmentRepository
	 */
	private InMemoryFulfillmentRepository $fulfillments;

	/**
	 * @var InMemoryFulfillmentItemRepository
	 */
	private InMemoryFulfillmentItemRepository $items;

	/**
	 * @var InMemoryShipmentRepository
	 */
	private InMemoryShipmentRepository $shipments;

	/**
	 * @var InMemoryPackageRepository
	 */
	private InMemoryPackageRepository $packages;

	/**
	 * @var InMemoryPackageItemRepository
	 */
	private InMemoryPackageItemRepository $package_items;

	/**
	 * @var InMemoryEventRepository
	 */
	private InMemoryEventRepository $events;

	/**
	 * @var ShippingService
	 */
	private ShippingService $service;

	/**
	 * @var int
	 */
	private int $fulfillment_id;

	protected function setUp(): void {
		$this->fulfillments  = new InMemoryFulfillmentRepository();
		$this->items         = new InMemoryFulfillmentItemRepository();
		$this->shipments     = new InMemoryShipmentRepository();
		$this->packages      = new InMemoryPackageRepository();
		$this->package_items = new InMemoryPackageItemRepository();
		$this->events        = new InMemoryEventRepository();

		$this->service = new ShippingService(
			$this->fulfillments,
			$this->items,
			$this->shipments,
			$this->packages,
			$this->package_items,
			$this->events,
			new EventDispatcher(),
			new FixedClock( new DateTimeImmutable( '2026-08-02 10:00:00' ) )
		);

		$this->fulfillment_id = $this->fulfillments->insert(
			Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'packing', '#1001', 'Jane Doe', 1, new DateTimeImmutable() )
		);

		$this->items->insert_all(
			array( FulfillmentItem::intake( $this->fulfillment_id, 501, 900, 0, 'SKU-1', 'Widget', 3 ) )
		);
	}

	public function test_create_shipment_auto_creates_package_one_and_allocates_packed_quantities(): void {
		$item = $this->items->find_for_fulfillment( $this->fulfillment_id )[0];
		$item->record_packed( 3 );
		$this->items->save( $item );

		$outcome = $this->service->create_shipment( $this->fulfillment_id, Actor::system() );

		self::assertTrue( $outcome->is_success() );

		$shipment = $outcome->result();
		self::assertInstanceOf( Shipment::class, $shipment );
		self::assertSame( Shipment::STATUS_PENDING, $shipment->status() );

		$packages = $this->packages->find_for_shipment( $shipment->id() );
		self::assertCount( 1, $packages );
		self::assertSame( 1, $packages[0]->seq() );

		$allocations = $this->package_items->find_for_package( $packages[0]->id() );
		self::assertCount( 1, $allocations );
		self::assertSame( 3, $allocations[0]->qty() );

		self::assertCount( 2, $this->events->timeline_for_fulfillment( $this->fulfillment_id ), 'shipment.created and package.created must both be audited.' );
	}

	public function test_create_shipment_advances_the_fulfillments_version(): void {
		$this->service->create_shipment( $this->fulfillment_id, Actor::system() );

		self::assertSame( 2, $this->fulfillments->find( $this->fulfillment_id )->version() );
	}

	public function test_create_shipment_fails_for_an_unknown_fulfillment(): void {
		$outcome = $this->service->create_shipment( 999999, Actor::system() );

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'not_found', $outcome->failure_code() );
	}

	public function test_update_shipment_sets_carrier_and_tracking(): void {
		$shipment_id = $this->service->create_shipment( $this->fulfillment_id, Actor::system() )->result()->id();

		$outcome = $this->service->update_shipment( $shipment_id, 'postnord', 'MyPack', 'ABC123', null, Actor::system() );

		self::assertTrue( $outcome->is_success() );

		$shipment = $this->shipments->find( $shipment_id );
		self::assertSame( 'postnord', $shipment->carrier_id() );
		self::assertTrue( $shipment->has_tracking() );
	}

	public function test_delete_shipment_succeeds_while_pending_and_removes_its_packages(): void {
		$shipment_id = $this->service->create_shipment( $this->fulfillment_id, Actor::system() )->result()->id();
		$package_id  = $this->packages->find_for_shipment( $shipment_id )[0]->id();

		$outcome = $this->service->delete_shipment( $shipment_id, Actor::system() );

		self::assertTrue( $outcome->is_success() );
		self::assertNull( $this->shipments->find( $shipment_id ) );
		self::assertNull( $this->packages->find( $package_id ) );
	}

	public function test_delete_shipment_is_refused_once_shipped(): void {
		$shipment_id = $this->service->create_shipment( $this->fulfillment_id, Actor::system() )->result()->id();
		$this->service->ship( $shipment_id, Actor::system() );

		$outcome = $this->service->delete_shipment( $shipment_id, Actor::system() );

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'not_deletable', $outcome->failure_code() );
		self::assertNotNull( $this->shipments->find( $shipment_id ), 'A shipped shipment must survive the refused delete attempt.' );
	}

	public function test_add_package_uses_the_next_sequence_number(): void {
		$shipment_id = $this->service->create_shipment( $this->fulfillment_id, Actor::system() )->result()->id();

		$outcome = $this->service->add_package( $shipment_id, Actor::system() );

		self::assertTrue( $outcome->is_success() );
		self::assertSame( 2, $outcome->result()->seq() );
		self::assertCount( 2, $this->packages->find_for_shipment( $shipment_id ) );
	}

	public function test_update_package_records_a_spec_and_colli_tracking_number(): void {
		$shipment_id = $this->service->create_shipment( $this->fulfillment_id, Actor::system() )->result()->id();
		$package_id  = $this->packages->find_for_shipment( $shipment_id )[0]->id();

		$outcome = $this->service->update_package( $package_id, 1200, 300, 200, 100, 'COLLI-1', Actor::system() );

		self::assertTrue( $outcome->is_success() );

		$package = $this->packages->find( $package_id );
		self::assertTrue( $package->spec()->is_present() );
		self::assertSame( 1200, $package->spec()->weight_grams() );
		self::assertSame( 'COLLI-1', $package->tracking_number() );
	}

	public function test_remove_package_deletes_its_allocations_too(): void {
		$shipment_id = $this->service->create_shipment( $this->fulfillment_id, Actor::system() )->result()->id();
		$package_id  = $this->packages->find_for_shipment( $shipment_id )[0]->id();

		$outcome = $this->service->remove_package( $package_id, Actor::system() );

		self::assertTrue( $outcome->is_success() );
		self::assertNull( $this->packages->find( $package_id ) );
		self::assertSame( array(), $this->package_items->find_for_package( $package_id ) );
	}

	public function test_ship_marks_the_shipment_shipped_and_stamps_shipped_at(): void {
		$shipment_id = $this->service->create_shipment( $this->fulfillment_id, Actor::system() )->result()->id();

		$outcome = $this->service->ship( $shipment_id, Actor::system() );

		self::assertTrue( $outcome->is_success() );

		$shipment = $this->shipments->find( $shipment_id );
		self::assertSame( Shipment::STATUS_SHIPPED, $shipment->status() );
		self::assertNotNull( $shipment->shipped_at() );
	}

	public function test_ship_all_pending_for_fulfillment_ships_every_pending_shipment_only(): void {
		$first  = $this->service->create_shipment( $this->fulfillment_id, Actor::system() )->result()->id();
		$second = $this->shipments->insert( Shipment::create( $this->fulfillment_id, new DateTimeImmutable() ) );

		$this->service->ship( $first, Actor::system() ); // Already shipped before the cascade runs.

		$this->service->ship_all_pending_for_fulfillment( $this->fulfillment_id, Actor::system() );

		self::assertSame( Shipment::STATUS_SHIPPED, $this->shipments->find( $first )->status() );
		self::assertSame( Shipment::STATUS_SHIPPED, $this->shipments->find( $second )->status() );
	}

	public function test_mark_delivered_and_mark_exception(): void {
		$shipment_id = $this->service->create_shipment( $this->fulfillment_id, Actor::system() )->result()->id();
		$this->service->ship( $shipment_id, Actor::system() );

		$delivered = $this->service->mark_delivered( $shipment_id, Actor::system() );
		self::assertTrue( $delivered->is_success() );
		self::assertSame( Shipment::STATUS_DELIVERED, $this->shipments->find( $shipment_id )->status() );

		$excepted = $this->service->mark_exception( $shipment_id, Actor::system() );
		self::assertTrue( $excepted->is_success() );
		self::assertSame( Shipment::STATUS_EXCEPTION, $this->shipments->find( $shipment_id )->status() );
	}

	public function test_attach_label_records_the_path_without_generating_anything(): void {
		$shipment_id = $this->service->create_shipment( $this->fulfillment_id, Actor::system() )->result()->id();
		$package_id  = $this->packages->find_for_shipment( $shipment_id )[0]->id();

		$outcome = $this->service->attach_label( $package_id, '/protected/labels/1.pdf', Actor::system() );

		self::assertTrue( $outcome->is_success() );
		self::assertSame( '/protected/labels/1.pdf', $this->packages->find( $package_id )->label_path() );
	}

	public function test_every_mutation_produces_a_valid_hash_chained_event(): void {
		$shipment_id = $this->service->create_shipment( $this->fulfillment_id, Actor::system() )->result()->id();
		$this->service->update_shipment( $shipment_id, 'postnord', '', 'ABC123', null, Actor::system() );
		$this->service->ship( $shipment_id, Actor::system() );

		$timeline = $this->events->timeline_for_fulfillment( $this->fulfillment_id );

		self::assertGreaterThanOrEqual( 4, count( $timeline ) );

		$previous_hash = null;

		foreach ( $timeline as $event ) {
			self::assertSame( $previous_hash, $event['prev_hash'] );
			$previous_hash = $event['hash'];
		}
	}
}
