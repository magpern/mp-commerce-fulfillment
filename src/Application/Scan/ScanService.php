<?php
/**
 * Sole Application entry for barcode scan mutations.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Scan;

use DateTimeImmutable;
use MPCF\Application\EventDispatcher;
use MPCF\Application\PackingService;
use MPCF\Domain\Clock;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Repository\EventRepository;
use MPCF\Domain\Repository\FulfillmentItemRepository;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Domain\Repository\PackageRepository;
use MPCF\Domain\Repository\ShipmentRepository;
use MPCF\Domain\Scan\ScanCorrectionStore;
use MPCF\Domain\Scan\ScanMode;
use MPCF\Domain\Scan\ScanResolution;
use MPCF\Domain\Scan\ScanResolver;

/**
 * Architecture Plan Part IX.10 — composes PackingService for absolute qty
 * writes; never mutates stock or inventory tables.
 */
final class ScanService {

	/**
	 * Fulfillment lookup.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

	/**
	 * Line items.
	 *
	 * @var FulfillmentItemRepository
	 */
	private FulfillmentItemRepository $items;

	/**
	 * Absolute quantity writer.
	 *
	 * @var PackingService
	 */
	private PackingService $packing;

	/**
	 * Pure barcode resolver.
	 *
	 * @var ScanResolver
	 */
	private ScanResolver $resolver;

	/**
	 * Package ownership checks.
	 *
	 * @var PackageRepository
	 */
	private PackageRepository $packages;

	/**
	 * Shipment ownership checks.
	 *
	 * @var ShipmentRepository
	 */
	private ShipmentRepository $shipments;

	/**
	 * Undo memory.
	 *
	 * @var ScanCorrectionStore
	 */
	private ScanCorrectionStore $corrections;

	/**
	 * Audit log.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * In-process dispatch.
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
	 * Builds the scan service.
	 *
	 * @param FulfillmentRepository     $fulfillments Fulfillment lookup.
	 * @param FulfillmentItemRepository $items        Line items.
	 * @param PackingService            $packing      Absolute quantity writer.
	 * @param ScanResolver              $resolver     Pure resolver.
	 * @param PackageRepository         $packages     Package ownership checks.
	 * @param ShipmentRepository        $shipments    Shipment ownership checks.
	 * @param ScanCorrectionStore       $corrections  Undo memory.
	 * @param EventRepository           $events       Audit log.
	 * @param EventDispatcher           $dispatcher   In-process dispatch.
	 * @param Clock                     $clock        Clock.
	 */
	public function __construct(
		FulfillmentRepository $fulfillments,
		FulfillmentItemRepository $items,
		PackingService $packing,
		ScanResolver $resolver,
		PackageRepository $packages,
		ShipmentRepository $shipments,
		ScanCorrectionStore $corrections,
		EventRepository $events,
		EventDispatcher $dispatcher,
		Clock $clock
	) {
		$this->fulfillments = $fulfillments;
		$this->items        = $items;
		$this->packing      = $packing;
		$this->resolver     = $resolver;
		$this->packages     = $packages;
		$this->shipments    = $shipments;
		$this->corrections  = $corrections;
		$this->events       = $events;
		$this->dispatcher   = $dispatcher;
		$this->clock        = $clock;
	}

	/**
	 * Resolves a payload without mutating quantities.
	 *
	 * @param int         $fulfillment_id Fulfillment id.
	 * @param string      $payload        Raw scan.
	 * @param string|null $mode           Optional mode for package eligibility messaging.
	 * @param int|null    $active_package_id Optional active package.
	 */
	public function resolve_scan( int $fulfillment_id, string $payload, ?string $mode = null, ?int $active_package_id = null ): ScanOutcome {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return ScanOutcome::failed( 'not_found', "No fulfillment exists with id {$fulfillment_id}." );
		}

		$items      = $this->items->find_for_fulfillment( $fulfillment_id );
		$resolution = $this->resolver->resolve( $payload, $items, $fulfillment_id );

		if ( $resolution->is_rejected() ) {
			return ScanOutcome::failed( $resolution->code(), $resolution->message(), $resolution );
		}

		if ( ScanResolution::STATUS_PACKAGE === $resolution->status() ) {
			$owned = $this->assert_package_owned( $fulfillment_id, (int) $resolution->identity_id() );

			if ( null !== $owned ) {
				return $owned;
			}

			return ScanOutcome::succeeded(
				'package_switched',
				'Active package switched.',
				$fulfillment->version(),
				$items,
				null,
				$resolution,
				false,
				(int) $resolution->identity_id(),
				$this->progress( $items, $mode ?? ScanMode::PACKING )
			);
		}

		if ( ScanResolution::STATUS_FULFILLMENT === $resolution->status() ) {
			return ScanOutcome::succeeded(
				'fulfillment_identity',
				'Fulfillment barcode recognized.',
				$fulfillment->version(),
				$items,
				null,
				$resolution,
				false,
				$active_package_id,
				$this->progress( $items, $mode ?? ScanMode::PICKING )
			);
		}

		return ScanOutcome::succeeded(
			'matched',
			'Item matched.',
			$fulfillment->version(),
			$items,
			$resolution->item(),
			$resolution,
			$this->is_stage_complete( $items, $mode ?? ScanMode::PICKING ),
			$active_package_id,
			$this->progress( $items, $mode ?? ScanMode::PICKING )
		);
	}

	/**
	 * Increments qty_picked by 1 for the resolved item.
	 *
	 * @param int      $fulfillment_id     Fulfillment id.
	 * @param int      $expected_version   Optimistic lock version.
	 * @param string   $payload            Raw scan.
	 * @param Actor    $actor              Operator.
	 * @param int|null $active_package_id  Ignored for picking; accepted for API symmetry.
	 */
	public function scan_pick( int $fulfillment_id, int $expected_version, string $payload, Actor $actor, ?int $active_package_id = null ): ScanOutcome {
		return $this->scan_quantity( ScanMode::PICKING, $fulfillment_id, $expected_version, $payload, $actor, $active_package_id );
	}

	/**
	 * Increments qty_packed by 1 for the resolved item.
	 *
	 * @param int      $fulfillment_id     Fulfillment id.
	 * @param int      $expected_version   Optimistic lock version.
	 * @param string   $payload            Raw scan.
	 * @param Actor    $actor              Operator.
	 * @param int|null $active_package_id  Optional active package (ownership validated when set).
	 */
	public function scan_pack( int $fulfillment_id, int $expected_version, string $payload, Actor $actor, ?int $active_package_id = null ): ScanOutcome {
		return $this->scan_quantity( ScanMode::PACKING, $fulfillment_id, $expected_version, $payload, $actor, $active_package_id );
	}

	/**
	 * Undoes the most recent successful scan for this operator/fulfillment.
	 *
	 * @param int   $fulfillment_id   Fulfillment id.
	 * @param int   $expected_version Optimistic lock version.
	 * @param Actor $actor            Operator.
	 */
	public function undo_last_scan( int $fulfillment_id, int $expected_version, Actor $actor ): ScanOutcome {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return ScanOutcome::failed( 'not_found', "No fulfillment exists with id {$fulfillment_id}." );
		}

		$user_id = $actor->id();

		if ( null === $user_id || $user_id <= 0 || Actor::TYPE_USER !== $actor->type() ) {
			return ScanOutcome::failed( 'undo_unavailable', 'Undo requires an authenticated operator session.' );
		}

		$entry = $this->corrections->pull( $user_id, $fulfillment_id );

		if ( null === $entry ) {
			return ScanOutcome::failed( 'undo_unavailable', 'There is no recent scan to undo.' );
		}

		$mode    = (string) ( $entry['mode'] ?? '' );
		$item_id = (int) ( $entry['item_id'] ?? 0 );
		$field   = ScanMode::is_valid( $mode ) ? ScanMode::quantity_field( $mode ) : '';

		if ( '' === $field || $item_id <= 0 ) {
			$this->corrections->clear( $user_id, $fulfillment_id );

			return ScanOutcome::failed( 'undo_unavailable', 'Stored scan correction is invalid.' );
		}

		if ( ScanMode::required_state( $mode ) !== $fulfillment->state() ) {
			$this->corrections->clear( $user_id, $fulfillment_id );

			return ScanOutcome::failed( 'wrong_stage', 'Undo is not available in the current workflow stage.' );
		}

		$items = $this->items->find_for_fulfillment( $fulfillment_id );
		$item  = null;

		foreach ( $items as $candidate ) {
			if ( (int) $candidate->id() === $item_id ) {
				$item = $candidate;
				break;
			}
		}

		if ( null === $item ) {
			$this->corrections->clear( $user_id, $fulfillment_id );

			return ScanOutcome::failed( 'undo_unavailable', 'The scanned item is no longer on this fulfillment.' );
		}

		$current      = 'qty_picked' === $field ? $item->qty_picked() : $item->qty_packed();
		$expected_qty = (int) ( $entry['resulting_qty'] ?? -1 );

		if ( $current !== $expected_qty || $current <= 0 ) {
			$this->corrections->clear( $user_id, $fulfillment_id );

			return ScanOutcome::failed( 'undo_unavailable', 'Quantities changed since the last scan; use manual correction.' );
		}

		$lines = array(
			array(
				'item_id' => $item_id,
				$field    => $current - 1,
			),
		);

		$packing = $this->packing->update_quantities( $fulfillment_id, $expected_version, $lines, $actor );

		if ( ! $packing->is_success() ) {
			return ScanOutcome::failed( (string) $packing->failure_code(), (string) $packing->failure_message() );
		}

		$this->corrections->clear( $user_id, $fulfillment_id );

		$fresh = $this->items->find_for_fulfillment( $fulfillment_id );
		$focus = null;

		foreach ( $fresh as $candidate ) {
			if ( (int) $candidate->id() === $item_id ) {
				$focus = $candidate;
				break;
			}
		}

		$this->record_scan_event(
			$fulfillment_id,
			'scan.corrected',
			$actor,
			array(
				'item_id'      => $item_id,
				'product_id'   => null !== $focus ? $focus->product_id() : 0,
				'variation_id' => null !== $focus ? $focus->variation_id() : 0,
				'mode'         => $mode,
				$field         => null !== $focus ? ( 'qty_picked' === $field ? $focus->qty_picked() : $focus->qty_packed() ) : $current - 1,
				'source'       => (string) ( $entry['source'] ?? 'sku' ),
			)
		);

		return ScanOutcome::succeeded(
			'corrected',
			'Last scan undone.',
			(int) $packing->version(),
			$fresh,
			$focus,
			null,
			$this->is_stage_complete( $fresh, $mode ),
			isset( $entry['package_id'] ) ? (int) $entry['package_id'] : null,
			$this->progress( $fresh, $mode )
		);
	}

	/**
	 * Shared pick/pack mutation path.
	 *
	 * @param string   $mode               Scan mode.
	 * @param int      $fulfillment_id     Fulfillment id.
	 * @param int      $expected_version   Version token.
	 * @param string   $payload            Raw scan.
	 * @param Actor    $actor              Operator.
	 * @param int|null $active_package_id  Optional package context.
	 */
	private function scan_quantity(
		string $mode,
		int $fulfillment_id,
		int $expected_version,
		string $payload,
		Actor $actor,
		?int $active_package_id
	): ScanOutcome {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return ScanOutcome::failed( 'not_found', "No fulfillment exists with id {$fulfillment_id}." );
		}

		if ( ScanMode::required_state( $mode ) !== $fulfillment->state() ) {
			return ScanOutcome::failed(
				'wrong_stage',
				sprintf( 'Scan Mode for %s is only available while the fulfillment is in that stage.', $mode )
			);
		}

		if ( null !== $active_package_id ) {
			$owned = $this->assert_package_owned( $fulfillment_id, $active_package_id );

			if ( null !== $owned ) {
				return $owned;
			}
		}

		$items      = $this->items->find_for_fulfillment( $fulfillment_id );
		$resolution = $this->resolver->resolve( $payload, $items, $fulfillment_id );

		if ( ScanResolution::STATUS_PACKAGE === $resolution->status() ) {
			$owned = $this->assert_package_owned( $fulfillment_id, (int) $resolution->identity_id() );

			if ( null !== $owned ) {
				return $owned;
			}

			return ScanOutcome::succeeded(
				'package_switched',
				'Active package switched.',
				$fulfillment->version(),
				$items,
				null,
				$resolution,
				false,
				(int) $resolution->identity_id(),
				$this->progress( $items, $mode )
			);
		}

		if ( ScanResolution::STATUS_FULFILLMENT === $resolution->status() ) {
			return ScanOutcome::succeeded(
				'fulfillment_identity',
				'Fulfillment barcode recognized — scan an item next.',
				$fulfillment->version(),
				$items,
				null,
				$resolution,
				false,
				$active_package_id,
				$this->progress( $items, $mode )
			);
		}

		if ( $resolution->is_rejected() || ! $resolution->is_item() ) {
			return ScanOutcome::failed( $resolution->code(), $resolution->message(), $resolution );
		}

		$item  = $resolution->item();
		$field = ScanMode::quantity_field( $mode );

		if ( ScanMode::PICKING === $mode ) {
			if ( $item->qty_picked() >= $item->qty_ordered() ) {
				return ScanOutcome::failed( 'over_scan', 'That item is already fully picked.', $resolution );
			}

			$next = $item->qty_picked() + 1;
		} else {
			if ( $item->qty_packed() >= $item->qty_ordered() ) {
				return ScanOutcome::failed( 'over_scan', 'That item is already fully packed.', $resolution );
			}

			if ( $item->qty_packed() >= $item->qty_picked() ) {
				return ScanOutcome::failed( 'not_yet_picked', 'Pick this item before packing it.', $resolution );
			}

			$next = $item->qty_packed() + 1;
		}

		$lines = array(
			array(
				'item_id' => (int) $item->id(),
				$field    => $next,
			),
		);

		$packing = $this->packing->update_quantities( $fulfillment_id, $expected_version, $lines, $actor );

		if ( ! $packing->is_success() ) {
			return ScanOutcome::failed( (string) $packing->failure_code(), (string) $packing->failure_message(), $resolution );
		}

		$fresh = $this->items->find_for_fulfillment( $fulfillment_id );
		$focus = null;

		foreach ( $fresh as $candidate ) {
			if ( (int) $candidate->id() === (int) $item->id() ) {
				$focus = $candidate;
				break;
			}
		}

		$resulting = null !== $focus
			? ( ScanMode::PICKING === $mode ? $focus->qty_picked() : $focus->qty_packed() )
			: $next;

		$user_id = $actor->id();

		if ( null !== $user_id && $user_id > 0 && Actor::TYPE_USER === $actor->type() ) {
			$this->corrections->remember(
				$user_id,
				$fulfillment_id,
				array(
					'mode'          => $mode,
					'item_id'       => (int) $item->id(),
					'resulting_qty' => $resulting,
					'source'        => (string) $resolution->source(),
					'package_id'    => $active_package_id,
				)
			);
		}

		$event_type = ScanMode::PICKING === $mode ? 'scan.item_picked' : 'scan.item_packed';
		$this->record_scan_event(
			$fulfillment_id,
			$event_type,
			$actor,
			array(
				'item_id'      => (int) $item->id(),
				'product_id'   => $item->product_id(),
				'variation_id' => $item->variation_id(),
				'mode'         => $mode,
				$field         => $resulting,
				'package_id'   => $active_package_id,
				'source'       => (string) $resolution->source(),
			)
		);

		$item_complete  = null !== $focus && (
			ScanMode::PICKING === $mode ? $focus->is_fully_picked() : $focus->is_fully_packed()
		);
		$stage_complete = $this->is_stage_complete( $fresh, $mode );
		$code           = $stage_complete ? 'stage_complete' : ( $item_complete ? 'item_complete' : 'quantity_incremented' );
		$message        = $stage_complete
			? 'Stage complete — all lines finished.'
			: ( $item_complete ? 'Item complete.' : 'Quantity incremented.' );

		return ScanOutcome::succeeded(
			$code,
			$message,
			(int) $packing->version(),
			$fresh,
			$focus,
			$resolution,
			$stage_complete,
			$active_package_id,
			$this->progress( $fresh, $mode )
		);
	}

	/**
	 * Validates package ownership; returns a failure outcome when invalid.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @param int $package_id     Package id.
	 */
	private function assert_package_owned( int $fulfillment_id, int $package_id ): ?ScanOutcome {
		$package = $this->packages->find( $package_id );

		if ( null === $package ) {
			return ScanOutcome::failed( 'package_not_found', 'That package does not exist.' );
		}

		$shipment = $this->shipments->find( $package->shipment_id() );

		if ( null === $shipment || (int) $shipment->fulfillment_id() !== $fulfillment_id ) {
			return ScanOutcome::failed( 'package_not_on_fulfillment', 'That package does not belong to this fulfillment.' );
		}

		return null;
	}

	/**
	 * Whether every line is complete for the active mode.
	 *
	 * @param array<int, FulfillmentItem> $items Lines.
	 * @param string                      $mode  Mode.
	 */
	private function is_stage_complete( array $items, string $mode ): bool {
		if ( array() === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( ScanMode::PICKING === $mode && ! $item->is_fully_picked() ) {
				return false;
			}

			if ( ScanMode::PACKING === $mode && ! $item->is_fully_packed() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Aggregates ordered/processed/remaining counts.
	 *
	 * @param array<int, FulfillmentItem> $items Lines.
	 * @param string                      $mode  Mode.
	 * @return array<string, int>
	 */
	private function progress( array $items, string $mode ): array {
		$ordered = 0;
		$done    = 0;

		foreach ( $items as $item ) {
			$ordered += $item->qty_ordered();
			$done    += ScanMode::PICKING === $mode ? $item->qty_picked() : $item->qty_packed();
		}

		return array(
			'ordered'   => $ordered,
			'processed' => $done,
			'remaining' => max( 0, $ordered - $done ),
		);
	}

	/**
	 * Appends a scan audit event.
	 *
	 * @param int                  $fulfillment_id Fulfillment id.
	 * @param string               $event_type     Event type.
	 * @param Actor                $actor          Actor.
	 * @param array<string, mixed> $payload        Payload.
	 */
	private function record_scan_event( int $fulfillment_id, string $event_type, Actor $actor, array $payload ): void {
		$now       = $this->clock->now();
		$event     = DomainEvent::for_fulfillment( $fulfillment_id, $event_type, $actor, $now, $payload );
		$prev_hash = $this->events->last_hash_for_fulfillment( $fulfillment_id );

		$this->events->append( $event, $prev_hash );
		$this->dispatcher->dispatch( $event );
	}
}
