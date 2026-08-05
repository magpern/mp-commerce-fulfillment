<?php
/**
 * Orchestrates notification send, dedup, audit, and status reads.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Notifications;

use DateTimeImmutable;
use MPCF\Application\EventDispatcher;
use MPCF\Domain\Clock;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\Notification\NotificationChannel;
use MPCF\Domain\Notification\NotificationResult;
use MPCF\Domain\Notification\NotificationStrategy;
use MPCF\Domain\Repository\EventRepository;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Domain\Repository\PackageRepository;
use MPCF\Domain\Repository\ShipmentRepository;
use MPCF\Domain\Shipping\Shipment;

/**
 * Application entry for M5-C. Dispatcher and REST both call this service.
 * Never throws across the mail boundary — failures become audited results.
 */
final class NotificationService {

	/**
	 * Dedup window in seconds for automatic ship-triggered sends.
	 */
	public const DEDUP_SECONDS = 120;

	/**
	 * Configuration service.
	 *
	 * @var NotificationConfigurationService
	 */
	private NotificationConfigurationService $config;

	/**
	 * Notification factory.
	 *
	 * @var NotificationFactory
	 */
	private NotificationFactory $factory;

	/**
	 * Email channel.
	 *
	 * @var NotificationChannel
	 */
	private NotificationChannel $email_channel;

	/**
	 * Fulfillment repository.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

	/**
	 * Shipment repository.
	 *
	 * @var ShipmentRepository
	 */
	private ShipmentRepository $shipments;

	/**
	 * Package repository.
	 *
	 * @var PackageRepository
	 */
	private PackageRepository $packages;

	/**
	 * Event repository.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * Domain event dispatcher.
	 *
	 * @var EventDispatcher
	 */
	private EventDispatcher $dispatcher;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Builds the service.
	 *
	 * @param NotificationConfigurationService $config        Configuration.
	 * @param NotificationFactory              $factory       Notification factory.
	 * @param NotificationChannel              $email_channel Email transport.
	 * @param FulfillmentRepository            $fulfillments  Fulfillments.
	 * @param ShipmentRepository               $shipments     Shipments.
	 * @param PackageRepository                $packages      Packages.
	 * @param EventRepository                  $events        Audit events.
	 * @param EventDispatcher                  $dispatcher    Event dispatcher.
	 * @param Clock                            $clock         Clock.
	 */
	public function __construct(
		NotificationConfigurationService $config,
		NotificationFactory $factory,
		NotificationChannel $email_channel,
		FulfillmentRepository $fulfillments,
		ShipmentRepository $shipments,
		PackageRepository $packages,
		EventRepository $events,
		EventDispatcher $dispatcher,
		Clock $clock
	) {
		$this->config        = $config;
		$this->factory       = $factory;
		$this->email_channel = $email_channel;
		$this->fulfillments  = $fulfillments;
		$this->shipments     = $shipments;
		$this->packages      = $packages;
		$this->events        = $events;
		$this->dispatcher    = $dispatcher;
		$this->clock         = $clock;
	}

	/**
	 * Sends (or suppresses) the MPCF shipped email for a shipment.
	 *
	 * @param int   $shipment_id Shipment id.
	 * @param Actor $actor       Actor causing the send.
	 * @param bool  $force       Bypass dedup (manual resend).
	 * @return array{status: string, result: NotificationResult|null, strategy: string}
	 */
	public function notify_shipment( int $shipment_id, Actor $actor, bool $force = false ): array {
		$strategy = $this->config->strategy();

		if ( $strategy->is_disabled() || ! $strategy->includes_mpcf_shipped() ) {
			return array(
				'status'   => 'skipped_strategy',
				'result'   => null,
				'strategy' => $strategy->value(),
			);
		}

		$shipment = $this->shipments->find( $shipment_id );
		if ( null === $shipment ) {
			return array(
				'status'   => 'not_found',
				'result'   => NotificationResult::failure( $this->email_channel->id(), 'not_found', 'Shipment not found.' ),
				'strategy' => $strategy->value(),
			);
		}

		$fulfillment = $this->fulfillments->find( $shipment->fulfillment_id() );
		if ( null === $fulfillment ) {
			return array(
				'status'   => 'not_found',
				'result'   => NotificationResult::failure( $this->email_channel->id(), 'not_found', 'Fulfillment not found.' ),
				'strategy' => $strategy->value(),
			);
		}

		if ( ! $force && $this->is_deduped( $shipment ) ) {
			$this->audit(
				(int) $fulfillment->id(),
				'notification.suppressed',
				$actor,
				array(
					'shipment_id' => $shipment_id,
					'channel'     => $this->email_channel->id(),
					'reason'      => 'dedup',
					'strategy'    => $strategy->value(),
				)
			);

			return array(
				'status'   => 'suppressed',
				'result'   => null,
				'strategy' => $strategy->value(),
			);
		}

		$packages     = $this->packages->find_for_shipment( (int) $shipment->id() );
		$notification = $this->factory->from_shipment( $fulfillment, $shipment, $packages );

		if ( null === $notification ) {
			$result = NotificationResult::failure( $this->email_channel->id(), 'missing_recipient', 'Customer email is missing.' );
			$this->audit(
				(int) $fulfillment->id(),
				'notification.failed',
				$actor,
				array(
					'shipment_id' => $shipment_id,
					'channel'     => $result->channel_id(),
					'error_code'  => $result->error_code(),
					'strategy'    => $strategy->value(),
				)
			);

			return array(
				'status'   => 'failed',
				'result'   => $result,
				'strategy' => $strategy->value(),
			);
		}

		try {
			$result = $this->email_channel->send( $notification );
		} catch ( \Throwable $exception ) {
			$result = NotificationResult::failure(
				$this->email_channel->id(),
				'send_exception',
				$exception->getMessage()
			);
		}

		$event_type = $result->is_success() ? 'notification.sent' : 'notification.failed';
		$payload    = array(
			'shipment_id' => $shipment_id,
			'channel'     => $result->channel_id(),
			'strategy'    => $strategy->value(),
			'carrier_id'  => $notification->carrier_id(),
		);
		if ( ! $result->is_success() ) {
			$payload['error_code'] = $result->error_code();
		}

		$this->audit( (int) $fulfillment->id(), $event_type, $actor, $payload );

		return array(
			'status'   => $result->is_success() ? 'sent' : 'failed',
			'result'   => $result,
			'strategy' => $strategy->value(),
		);
	}

	/**
	 * Last notification status for a shipment (from audit trail).
	 *
	 * @param int $shipment_id Shipment id.
	 * @return array{status: string|null, occurred_at: string|null, strategy: string, error_code: string|null}
	 */
	public function status_for_shipment( int $shipment_id ): array {
		$shipment = $this->shipments->find( $shipment_id );
		$strategy = $this->config->strategy()->value();

		if ( null === $shipment ) {
			return array(
				'status'      => null,
				'occurred_at' => null,
				'strategy'    => $strategy,
				'error_code'  => 'not_found',
			);
		}

		$events = $this->events->recent_for_fulfillment( $shipment->fulfillment_id(), 50 );
		for ( $i = count( $events ) - 1; $i >= 0; $i-- ) {
			$event = $events[ $i ];
			$type  = (string) ( $event['event_type'] ?? '' );
			if ( ! in_array( $type, array( 'notification.sent', 'notification.failed', 'notification.suppressed' ), true ) ) {
				continue;
			}
			$payload = (array) ( $event['payload'] ?? array() );
			if ( (int) ( $payload['shipment_id'] ?? 0 ) !== $shipment_id ) {
				continue;
			}

			$status = 'notification.sent' === $type ? 'sent' : ( 'notification.failed' === $type ? 'failed' : 'suppressed' );

			$occurred_raw = $event['created_at'] ?? null;
			$occurred_at  = null;
			if ( $occurred_raw instanceof DateTimeImmutable ) {
				$occurred_at = $occurred_raw->format( DATE_ATOM );
			} elseif ( null !== $occurred_raw ) {
				$occurred_at = (string) $occurred_raw;
			}

			return array(
				'status'      => $status,
				'occurred_at' => $occurred_at,
				'strategy'    => (string) ( $payload['strategy'] ?? $strategy ),
				'error_code'  => isset( $payload['error_code'] ) ? (string) $payload['error_code'] : null,
			);
		}

		return array(
			'status'      => null,
			'occurred_at' => null,
			'strategy'    => $strategy,
			'error_code'  => null,
		);
	}

	/**
	 * Current strategy (for TrackingEmailExtension / REST).
	 */
	public function strategy(): NotificationStrategy {
		return $this->config->strategy();
	}

	/**
	 * Whether a recent successful or suppressed send blocks another auto-send.
	 *
	 * @param Shipment $shipment Shipment under consideration.
	 */
	private function is_deduped( Shipment $shipment ): bool {
		$cutoff = $this->clock->now()->getTimestamp() - self::DEDUP_SECONDS;
		$events = $this->events->recent_for_fulfillment( $shipment->fulfillment_id(), 30 );

		foreach ( array_reverse( $events ) as $event ) {
			$type = (string) ( $event['event_type'] ?? '' );
			if ( ! in_array( $type, array( 'notification.sent', 'notification.suppressed' ), true ) ) {
				continue;
			}
			$payload = (array) ( $event['payload'] ?? array() );
			if ( (int) ( $payload['shipment_id'] ?? 0 ) !== (int) $shipment->id() ) {
				continue;
			}
			$occurred = null;
			if ( isset( $event['created_at'] ) && $event['created_at'] instanceof DateTimeImmutable ) {
				$occurred = $event['created_at']->getTimestamp();
			} elseif ( isset( $event['created_at'] ) ) {
				$parsed   = strtotime( (string) $event['created_at'] );
				$occurred = false !== $parsed ? $parsed : null;
			}
			if ( null !== $occurred && $occurred >= $cutoff ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Appends and dispatches an audit event.
	 *
	 * @param int                  $fulfillment_id Fulfillment id.
	 * @param string               $event_type     Event type.
	 * @param Actor                $actor          Actor.
	 * @param array<string, mixed> $payload        Safe payload.
	 */
	private function audit( int $fulfillment_id, string $event_type, Actor $actor, array $payload ): void {
		$now       = $this->clock->now();
		$event     = DomainEvent::for_fulfillment( $fulfillment_id, $event_type, $actor, $now, $payload );
		$prev_hash = $this->events->last_hash_for_fulfillment( $fulfillment_id );
		$this->events->append( $event, $prev_hash );
		$this->dispatcher->dispatch( $event );
	}
}
