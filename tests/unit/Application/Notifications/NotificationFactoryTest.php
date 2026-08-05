<?php
/**
 * Tests for NotificationFactory tracking URL assembly.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Notifications;

use DateTimeImmutable;
use MPCF\Application\Notifications\NotificationConfigurationService;
use MPCF\Application\Notifications\NotificationFactory;
use MPCF\Domain\CustomerEmailLookup;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\Notification\NotificationStrategy;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Domain\Shipping\TrackingReference;
use MPCF\Infrastructure\Carriers\BundledCarrierRegistry;
use MPCF\Settings;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryShipmentRepository;
use PHPUnit\Framework\TestCase;

/**
 * Factory builds transport-independent Notifications with carrier URLs.
 */
final class NotificationFactoryTest extends TestCase {

	protected function setUp(): void {
		mpcf_tests_reset_wp_state();
	}

	public function test_from_shipment_resolves_tracking_url_from_carrier_template(): void {
		$factory = $this->factory( 'buyer@example.com' );

		$now          = new DateTimeImmutable( '2026-08-05T12:00:00+00:00' );
		$fulfillments = new InMemoryFulfillmentRepository();
		$shipments    = new InMemoryShipmentRepository();
		$fid          = $fulfillments->insert(
			Fulfillment::intake( 42, 'woocommerce', 1, 'standard', 'shipped', '#42', 'Buyer', 1, $now )
		);
		$shipment     = Shipment::create( $fid, $now );
		$shipment->set_carrier( 'postnord' );
		$shipment->set_tracking( TrackingReference::create( 'ABC123', null ) );
		$shipment->mark_shipped( $now );
		$sid = $shipments->insert( $shipment );

		$fulfillment = $fulfillments->find( $fid );
		$shipment    = $shipments->find( $sid );
		self::assertNotNull( $fulfillment );
		self::assertNotNull( $shipment );

		$notification = $factory->from_shipment( $fulfillment, $shipment, array() );

		self::assertNotNull( $notification );
		self::assertSame( 'buyer@example.com', $notification->recipient_email() );
		self::assertSame( 'ABC123', $notification->tracking_number() );
		self::assertNotNull( $notification->tracking_url() );
		self::assertStringContainsString( 'ABC123', (string) $notification->tracking_url() );
		self::assertStringContainsString( 'PostNord', $notification->html_body() );
	}

	public function test_from_shipment_returns_null_without_email(): void {
		$factory = $this->factory( null );

		$now          = new DateTimeImmutable( '2026-08-05T12:00:00+00:00' );
		$fulfillments = new InMemoryFulfillmentRepository();
		$shipments    = new InMemoryShipmentRepository();
		$fid          = $fulfillments->insert(
			Fulfillment::intake( 42, 'woocommerce', 1, 'standard', 'shipped', '#42', 'Buyer', 1, $now )
		);
		$shipment     = Shipment::create( $fid, $now );
		$shipment->set_carrier( 'other' );
		$shipment->set_tracking( TrackingReference::create( 'X', 'https://example.test/x' ) );
		$shipment->mark_shipped( $now );
		$sid = $shipments->insert( $shipment );

		self::assertNull(
			$factory->from_shipment( $fulfillments->find( $fid ), $shipments->find( $sid ), array() )
		);
	}

	/**
	 * @param string|null $email Customer email.
	 */
	private function factory( ?string $email ): NotificationFactory {
		$config = new NotificationConfigurationService(
			new Settings(
				array(
					'notification_strategy'           => NotificationStrategy::MPCF_SHIPPED,
					'notification_email_subject'      => 'Shipped',
					'notification_email_introduction' => 'Hello',
					'notification_email_signature'    => 'Bye',
				)
			),
			new BundledCarrierRegistry()
		);

		return new NotificationFactory(
			$config,
			new BundledCarrierRegistry(),
			new class( $email ) implements CustomerEmailLookup {
				/** @var string|null */
				private ?string $email;

				public function __construct( ?string $email ) {
					$this->email = $email;
				}

				public function email_for_order( int $order_id ): ?string {
					unset( $order_id );

					return $this->email;
				}
			}
		);
	}
}
