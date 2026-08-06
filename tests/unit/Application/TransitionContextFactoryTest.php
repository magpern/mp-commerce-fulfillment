<?php
/**
 * Tests for the real-data transition-context builder.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application;

use DateTimeImmutable;
use MPCF\Application\EventDispatcher;
use MPCF\Application\Photos\PhotoService;
use MPCF\Application\TransitionContextFactory;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Media\PhotoKind;
use MPCF\Domain\Shipping\Package;
use MPCF\Domain\Shipping\PackageSpec;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Domain\Shipping\TrackingReference;
use MPCF\Infrastructure\Files\ProtectedPhotoStore;
use MPCF\Settings;
use MPCF\Tests\Unit\Application\Doubles\FixedClock;
use MPCF\Tests\Unit\Application\Doubles\InMemoryEventRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentItemRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryMediaRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryPackageRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryShipmentRepository;
use MPCF\Tests\Unit\Support\FakeImageProcessor;
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
	 * @var string
	 */
	private string $photo_root;

	/**
	 * @var TransitionContextFactory
	 */
	private TransitionContextFactory $factory;

	protected function setUp(): void {
		$this->items      = new InMemoryFulfillmentItemRepository();
		$this->shipments  = new InMemoryShipmentRepository();
		$this->packages   = new InMemoryPackageRepository();
		$this->photo_root = sys_get_temp_dir() . '/mpcf-ctx-photo-' . uniqid( '', true );
		$this->factory    = $this->factory_with( array() );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->photo_root ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->photo_root, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $this->photo_root );
		}
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $settings_overrides Raw settings overrides.
	 * @param PhotoService|null    $photos             Optional photo service.
	 */
	private function factory_with( array $settings_overrides, ?PhotoService $photos = null ): TransitionContextFactory {
		return new TransitionContextFactory(
			$this->items,
			$this->shipments,
			$this->packages,
			new Settings( $settings_overrides ),
			$photos
		);
	}

	/**
	 * Builds a PhotoService wired to empty in-memory media for factory tests.
	 *
	 * @param InMemoryFulfillmentRepository $fulfillments Fulfillment repo.
	 * @param InMemoryMediaRepository|null  $media        Optional media repo.
	 */
	private function photo_service(
		InMemoryFulfillmentRepository $fulfillments,
		?InMemoryMediaRepository $media = null
	): PhotoService {
		return new PhotoService(
			$media ?? new InMemoryMediaRepository(),
			new ProtectedPhotoStore( $this->photo_root ),
			new FakeImageProcessor(),
			$fulfillments,
			$this->packages,
			$this->shipments,
			new InMemoryEventRepository(),
			new EventDispatcher(),
			new FixedClock( new DateTimeImmutable( '2026-08-06 12:00:00' ) )
		);
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

	public function test_tracking_requirement_is_unsatisfied_when_required_and_no_shipment_has_tracking(): void {
		$this->shipments->insert( Shipment::create( 1, new DateTimeImmutable() ) );

		$factory = $this->factory_with( array( 'require_tracking_before_ship' => true ) );

		self::assertFalse( $factory->build( 1 )->tracking_requirement_satisfied() );
	}

	public function test_tracking_requirement_is_satisfied_when_required_and_a_shipment_has_tracking(): void {
		$shipment = Shipment::create( 1, new DateTimeImmutable() );
		$shipment->set_tracking( TrackingReference::create( 'TRACK-1' ) );
		$this->shipments->insert( $shipment );

		$factory = $this->factory_with( array( 'require_tracking_before_ship' => true ) );

		self::assertTrue( $factory->build( 1 )->tracking_requirement_satisfied() );
	}

	public function test_tracking_requirement_is_satisfied_when_required_but_no_shipment_exists(): void {
		// No shipment at all is HasShipmentGuard's rejection, not this
		// one's — this guard must not additionally reject with its own
		// (misleading, "no tracking recorded") message for a fulfillment
		// that has no shipment to have tracking on in the first place.
		$factory = $this->factory_with( array( 'require_tracking_before_ship' => true ) );

		self::assertTrue( $factory->build( 1 )->tracking_requirement_satisfied() );
	}

	public function test_photo_requirement_is_satisfied_when_photos_required_is_off(): void {
		$factory = $this->factory_with( array( 'photos_required' => false ) );

		self::assertTrue( $factory->build( 1 )->photo_requirement_satisfied() );
	}

	public function test_photo_requirement_is_unsatisfied_when_required_and_no_package_photo(): void {
		$fulfillments = new InMemoryFulfillmentRepository();
		$fulfillments->insert(
			Fulfillment::intake( 1, 'woocommerce', 1, 'standard', 'packing', '#1', 'A', 1, new DateTimeImmutable() )
		);
		$photos  = $this->photo_service( $fulfillments );
		$factory = $this->factory_with( array( 'photos_required' => true ), $photos );

		self::assertFalse( $factory->build( 1 )->photo_requirement_satisfied() );
	}

	public function test_photo_requirement_is_satisfied_when_required_and_a_package_photo_exists(): void {
		$fulfillments = new InMemoryFulfillmentRepository();
		$now          = new DateTimeImmutable( '2026-08-06 12:00:00' );
		$fid          = $fulfillments->insert(
			Fulfillment::intake( 1, 'woocommerce', 1, 'standard', 'packing', '#1', 'A', 1, $now )
		);
		$sid          = $this->shipments->insert( Shipment::create( $fid, $now ) );
		$pid          = $this->packages->insert( Package::create( $sid, 1, $now ) );

		$photos = $this->photo_service( $fulfillments );
		$photos->capture( $fid, $pid, PhotoKind::PACKAGE, 'raw', 'image/jpeg', Actor::system() );

		$factory = $this->factory_with( array( 'photos_required' => true ), $photos );

		self::assertTrue( $factory->build( $fid )->photo_requirement_satisfied() );
	}

	public function test_photo_requirement_is_unsatisfied_when_required_but_photos_service_is_null(): void {
		$factory = $this->factory_with( array( 'photos_required' => true ), null );

		self::assertFalse( $factory->build( 1 )->photo_requirement_satisfied() );
	}
}
