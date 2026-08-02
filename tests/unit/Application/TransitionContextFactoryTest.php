<?php
/**
 * Tests for the real-data transition-context builder.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application;

use DateTimeImmutable;
use MPCF\Application\TransitionContextFactory;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Shipping\Package;
use MPCF\Domain\Shipping\PackageSpec;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentItemRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryPackageRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryShipmentRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the real-data transition-context builder.
 */
final class TransitionContextFactoryTest extends TestCase {

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
	 * @var TransitionContextFactory
	 */
	private TransitionContextFactory $factory;

	protected function setUp(): void {
		$this->items     = new InMemoryFulfillmentItemRepository();
		$this->shipments = new InMemoryShipmentRepository();
		$this->packages  = new InMemoryPackageRepository();
		$this->factory   = new TransitionContextFactory( $this->items, $this->shipments, $this->packages );
	}

	public function test_a_fulfillment_with_no_shipment_has_neither_flag_present(): void {
		$context = $this->factory->build( 1 );

		self::assertFalse( $context->has_shipment() );
		self::assertFalse( $context->package_spec_present() );
	}

	public function test_a_shipment_with_no_spec_present_package_reports_has_shipment_but_not_package_spec_present(): void {
		$this->shipments->insert( Shipment::create( 1, new DateTimeImmutable() ) );

		$context = $this->factory->build( 1 );

		self::assertTrue( $context->has_shipment() );
		self::assertFalse( $context->package_spec_present() );
	}

	public function test_a_package_with_a_recorded_weight_satisfies_package_spec_present(): void {
		$shipment_id = $this->shipments->insert( Shipment::create( 1, new DateTimeImmutable() ) );
		$package     = Package::create( $shipment_id, 1, new DateTimeImmutable() );
		$package->set_spec( PackageSpec::create( 500, null, null, null ) );
		$this->packages->insert( $package );

		$context = $this->factory->build( 1 );

		self::assertTrue( $context->package_spec_present() );
	}

	public function test_items_are_passed_through_unchanged(): void {
		$this->items->insert_all( array( FulfillmentItem::intake( 1, 501, 900, 0, 'SKU-1', 'Widget', 3 ) ) );

		$context = $this->factory->build( 1 );

		self::assertCount( 1, $context->items() );
		self::assertSame( 'SKU-1', $context->items()[0]->sku_snapshot() );
	}

	public function test_photo_and_tracking_requirements_default_to_satisfied(): void {
		$context = $this->factory->build( 1 );

		self::assertTrue( $context->photo_requirement_satisfied() );
		self::assertTrue( $context->tracking_requirement_satisfied() );
	}
}
