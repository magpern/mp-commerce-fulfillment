<?php
/**
 * Unit tests for TrackingEmailExtension strategy gating.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Woo;

use DateTimeImmutable;
use MPCF\Application\Notifications\NotificationConfigurationService;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\Notification\NotificationStrategy;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Domain\Shipping\TrackingReference;
use MPCF\Infrastructure\Carriers\BundledCarrierRegistry;
use MPCF\Settings;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryPackageRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryShipmentRepository;
use MPCF\Woo\TrackingEmailExtension;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Proves completed-order extension reads strategy and shipment tracking.
 */
final class TrackingEmailExtensionTest extends TestCase {

	protected function setUp(): void {
		mpcf_tests_reset_wp_state();
	}

	public function test_tracking_blocks_include_shipped_shipment(): void {
		$fulfillments = new InMemoryFulfillmentRepository();
		$shipments    = new InMemoryShipmentRepository();
		$packages     = new InMemoryPackageRepository();
		$carriers     = new BundledCarrierRegistry();
		$now          = new DateTimeImmutable( '2026-08-05T12:00:00+00:00' );

		$fid      = $fulfillments->insert(
			Fulfillment::intake( 9001, 'woocommerce', 1, 'standard', 'shipped', '#9001', 'Ada', 1, $now )
		);
		$shipment = Shipment::create( $fid, $now );
		$shipment->set_carrier( 'postnord' );
		$shipment->set_tracking( TrackingReference::create( 'TRK-1', null ) );
		$shipment->mark_shipped( $now );
		$shipments->insert( $shipment );

		$ext = new TrackingEmailExtension(
			new NotificationConfigurationService(
				new Settings( array( 'notification_strategy' => NotificationStrategy::COMPLETED_EMAIL ) ),
				$carriers
			),
			$fulfillments,
			$shipments,
			$packages,
			$carriers
		);

		$method = new ReflectionMethod( TrackingEmailExtension::class, 'tracking_blocks_for_order' );
		$method->setAccessible( true );
		$blocks = $method->invoke( $ext, 9001 );

		self::assertCount( 1, $blocks );
		self::assertSame( 'TRK-1', $blocks[0]['tracking_number'] );
		self::assertStringContainsString( 'TRK-1', (string) $blocks[0]['tracking_url'] );
	}

	public function test_pending_shipments_are_omitted_from_blocks(): void {
		$fulfillments = new InMemoryFulfillmentRepository();
		$shipments    = new InMemoryShipmentRepository();
		$packages     = new InMemoryPackageRepository();
		$carriers     = new BundledCarrierRegistry();
		$now          = new DateTimeImmutable( '2026-08-05T12:00:00+00:00' );

		$fid      = $fulfillments->insert(
			Fulfillment::intake( 9002, 'woocommerce', 1, 'standard', 'packed', '#9002', 'Ada', 1, $now )
		);
		$shipment = Shipment::create( $fid, $now );
		$shipment->set_carrier( 'postnord' );
		$shipment->set_tracking( TrackingReference::create( 'PENDING', null ) );
		$shipments->insert( $shipment );

		$ext = new TrackingEmailExtension(
			new NotificationConfigurationService(
				new Settings( array( 'notification_strategy' => NotificationStrategy::BOTH ) ),
				$carriers
			),
			$fulfillments,
			$shipments,
			$packages,
			$carriers
		);

		$method = new ReflectionMethod( TrackingEmailExtension::class, 'tracking_blocks_for_order' );
		$method->setAccessible( true );

		self::assertSame( array(), $method->invoke( $ext, 9002 ) );
	}
}
