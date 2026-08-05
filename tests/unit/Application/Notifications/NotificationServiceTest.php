<?php
/**
 * Tests for NotificationService / dispatcher pipeline.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Notifications;

use DateTimeImmutable;
use MPCF\Application\EventDispatcher;
use MPCF\Application\Notifications\NotificationConfigurationService;
use MPCF\Application\Notifications\NotificationDispatcher;
use MPCF\Application\Notifications\NotificationFactory;
use MPCF\Application\Notifications\NotificationService;
use MPCF\Domain\CustomerEmailLookup;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\Notification\NotificationStrategy;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Domain\Shipping\TrackingReference;
use MPCF\Infrastructure\Carriers\BundledCarrierRegistry;
use MPCF\Settings;
use MPCF\Tests\Unit\Application\Doubles\FixedClock;
use MPCF\Tests\Unit\Application\Doubles\InMemoryEventRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryPackageRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryShipmentRepository;
use PHPUnit\Framework\TestCase;

/**
 * Notification pipeline: strategy gating, missing email, send, audit.
 */
final class NotificationServiceTest extends TestCase {

	/**
	 * @var InMemoryFulfillmentRepository
	 */
	private InMemoryFulfillmentRepository $fulfillments;

	/**
	 * @var InMemoryShipmentRepository
	 */
	private InMemoryShipmentRepository $shipments;

	/**
	 * @var InMemoryPackageRepository
	 */
	private InMemoryPackageRepository $packages;

	/**
	 * @var InMemoryEventRepository
	 */
	private InMemoryEventRepository $events;

	/**
	 * @var RecordingEmailChannel
	 */
	private RecordingEmailChannel $channel;

	/**
	 * @var StubCustomerEmailLookup
	 */
	private StubCustomerEmailLookup $emails;

	/**
	 * @var int
	 */
	private int $fulfillment_id;

	/**
	 * @var int
	 */
	private int $shipment_id;

	protected function setUp(): void {
		mpcf_tests_reset_wp_state();

		$this->fulfillments = new InMemoryFulfillmentRepository();
		$this->shipments    = new InMemoryShipmentRepository();
		$this->packages     = new InMemoryPackageRepository();
		$this->events       = new InMemoryEventRepository();
		$this->channel      = new RecordingEmailChannel();
		$this->emails       = new StubCustomerEmailLookup( 'customer@example.com' );

		$now                  = new DateTimeImmutable( '2026-08-05T12:00:00+00:00' );
		$fulfillment          = Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'shipped', '#1001', 'Ada Lovelace', 1, $now );
		$this->fulfillment_id = $this->fulfillments->insert( $fulfillment );

		$shipment = Shipment::create( $this->fulfillment_id, $now );
		$shipment->set_carrier( 'postnord' );
		$shipment->set_tracking( TrackingReference::create( 'TRACK123', null ) );
		$shipment->mark_shipped( $now );
		$this->shipment_id = $this->shipments->insert( $shipment );
	}

	public function test_disabled_strategy_skips_send(): void {
		$service = $this->service( array( 'notification_strategy' => NotificationStrategy::DISABLED ) );
		$outcome = $service->notify_shipment( $this->shipment_id, Actor::system(), true );

		self::assertSame( 'skipped_strategy', $outcome['status'] );
		self::assertSame( 0, $this->channel->send_count );
	}

	public function test_completed_email_strategy_skips_mpcf_channel(): void {
		$service = $this->service( array( 'notification_strategy' => NotificationStrategy::COMPLETED_EMAIL ) );
		$outcome = $service->notify_shipment( $this->shipment_id, Actor::system(), true );

		self::assertSame( 'skipped_strategy', $outcome['status'] );
		self::assertSame( 0, $this->channel->send_count );
	}

	public function test_mpcf_shipped_sends_and_audits(): void {
		$service = $this->service( array( 'notification_strategy' => NotificationStrategy::MPCF_SHIPPED ) );
		$outcome = $service->notify_shipment( $this->shipment_id, Actor::system(), true );

		self::assertSame( 'sent', $outcome['status'] );
		self::assertSame( 1, $this->channel->send_count );
		self::assertNotNull( $this->channel->last );
		self::assertSame( 'customer@example.com', $this->channel->last->recipient_email() );

		$types = array_column( $this->events->timeline_for_fulfillment( $this->fulfillment_id ), 'event_type' );
		self::assertContains( 'notification.sent', $types );
	}

	public function test_missing_recipient_audits_failure(): void {
		$this->emails->email = null;
		$service             = $this->service( array( 'notification_strategy' => NotificationStrategy::MPCF_SHIPPED ) );
		$outcome             = $service->notify_shipment( $this->shipment_id, Actor::system(), true );

		self::assertSame( 'failed', $outcome['status'] );
		self::assertSame( 'missing_recipient', $outcome['result']->error_code() );
		$types = array_column( $this->events->timeline_for_fulfillment( $this->fulfillment_id ), 'event_type' );
		self::assertContains( 'notification.failed', $types );
	}

	public function test_dispatcher_handles_shipment_shipped_event(): void {
		$service    = $this->service( array( 'notification_strategy' => NotificationStrategy::BOTH ) );
		$dispatcher = new NotificationDispatcher( $service );
		$event      = DomainEvent::for_fulfillment(
			$this->fulfillment_id,
			'shipment.shipped',
			Actor::system(),
			new DateTimeImmutable( '2026-08-05T12:00:00+00:00' ),
			array( 'shipment_id' => $this->shipment_id )
		);

		$dispatcher->handle( $event );

		self::assertSame( 1, $this->channel->send_count );
	}

	public function test_status_reports_last_send(): void {
		$service = $this->service( array( 'notification_strategy' => NotificationStrategy::MPCF_SHIPPED ) );
		$service->notify_shipment( $this->shipment_id, Actor::system(), true );

		$status = $service->status_for_shipment( $this->shipment_id );

		self::assertSame( 'sent', $status['status'] );
		self::assertNotNull( $status['occurred_at'] );
	}

	public function test_auto_send_dedupes_within_window(): void {
		$service = $this->service( array( 'notification_strategy' => NotificationStrategy::MPCF_SHIPPED ) );
		$service->notify_shipment( $this->shipment_id, Actor::system(), true );
		$second = $service->notify_shipment( $this->shipment_id, Actor::system(), false );

		self::assertSame( 'suppressed', $second['status'] );
		self::assertSame( 1, $this->channel->send_count );
	}

	public function test_force_bypasses_dedup(): void {
		$service = $this->service( array( 'notification_strategy' => NotificationStrategy::MPCF_SHIPPED ) );
		$service->notify_shipment( $this->shipment_id, Actor::system(), true );
		$second = $service->notify_shipment( $this->shipment_id, Actor::system(), true );

		self::assertSame( 'sent', $second['status'] );
		self::assertSame( 2, $this->channel->send_count );
	}

	/**
	 * @param array<string, mixed> $settings_overrides Settings overrides.
	 */
	private function service( array $settings_overrides ): NotificationService {
		$settings = new Settings( $settings_overrides );
		$carriers = new BundledCarrierRegistry();
		$config   = new NotificationConfigurationService( $settings, $carriers );

		return new NotificationService(
			$config,
			new NotificationFactory( $config, $carriers, $this->emails ),
			$this->channel,
			$this->fulfillments,
			$this->shipments,
			$this->packages,
			$this->events,
			new EventDispatcher(),
			new FixedClock( new DateTimeImmutable( '2026-08-05T12:00:00+00:00' ) )
		);
	}
}
