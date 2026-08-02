<?php
/**
 * Shipment and package lifecycle.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use DateTimeImmutable;
use MPCF\Domain\Clock;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\Repository\EventRepository;
use MPCF\Domain\Repository\FulfillmentItemRepository;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Domain\Repository\PackageItemRepository;
use MPCF\Domain\Repository\PackageRepository;
use MPCF\Domain\Repository\ShipmentRepository;
use MPCF\Domain\Shipping\Package;
use MPCF\Domain\Shipping\PackageItem;
use MPCF\Domain\Shipping\PackageSpec;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Domain\Shipping\TrackingReference;

/**
 * Architecture Plan §IV.6/§IV.5.8. Every mutation here advances the owning
 * fulfillment's optimistic-lock version via
 * {@see FulfillmentRepository::touch()} — never `mpcf_fulfillments.state`
 * itself (I4 remains `WorkflowService`'s alone) — and appends exactly one
 * hash-chained audit event, matching {@see WorkflowService}'s own
 * single-writer, always-audited discipline for the aggregate's other half.
 *
 * `create_shipment()` is the "first edit to any shipment field creates the
 * shipment" moment (§IV.5.8 step 6): it creates the shipment *and* its
 * package 1 in one call, and auto-allocates every currently-packed line
 * quantity to that package — Milestone 2's line-allocation rule (PO
 * decision, §IV.0.2); a future milestone's real split UI replaces only
 * that one allocation step, nothing else here.
 */
final class ShippingService {

	/**
	 * Fulfillment lookup, for version-touch and item auto-allocation.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

	/**
	 * Line items, for auto-allocating packed quantities to package 1.
	 *
	 * @var FulfillmentItemRepository
	 */
	private FulfillmentItemRepository $items;

	/**
	 * Shipment persistence.
	 *
	 * @var ShipmentRepository
	 */
	private ShipmentRepository $shipments;

	/**
	 * Package persistence.
	 *
	 * @var PackageRepository
	 */
	private PackageRepository $packages;

	/**
	 * Per-package line-quantity allocation persistence.
	 *
	 * @var PackageItemRepository
	 */
	private PackageItemRepository $package_items;

	/**
	 * Audit log persistence.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * In-process event dispatch.
	 *
	 * @var EventDispatcher
	 */
	private EventDispatcher $dispatcher;

	/**
	 * Source of "now".
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Builds the service.
	 *
	 * @param FulfillmentRepository     $fulfillments  Fulfillment lookup, for version-touch and item auto-allocation.
	 * @param FulfillmentItemRepository $items         Line items, for auto-allocating packed quantities.
	 * @param ShipmentRepository        $shipments     Shipment persistence.
	 * @param PackageRepository         $packages      Package persistence.
	 * @param PackageItemRepository     $package_items Per-package line-quantity allocation persistence.
	 * @param EventRepository           $events        Audit log persistence.
	 * @param EventDispatcher           $dispatcher    In-process event dispatch.
	 * @param Clock                     $clock         Source of "now".
	 */
	public function __construct(
		FulfillmentRepository $fulfillments,
		FulfillmentItemRepository $items,
		ShipmentRepository $shipments,
		PackageRepository $packages,
		PackageItemRepository $package_items,
		EventRepository $events,
		EventDispatcher $dispatcher,
		Clock $clock
	) {
		$this->fulfillments  = $fulfillments;
		$this->items         = $items;
		$this->shipments     = $shipments;
		$this->packages      = $packages;
		$this->package_items = $package_items;
		$this->events        = $events;
		$this->dispatcher    = $dispatcher;
		$this->clock         = $clock;
	}

	/**
	 * Creates a new shipment for a fulfillment, together with its package
	 * 1, auto-allocated with every currently-packed line quantity.
	 *
	 * @param int   $fulfillment_id Fulfillment to create a shipment for.
	 * @param Actor $actor          Who is creating it.
	 */
	public function create_shipment( int $fulfillment_id, Actor $actor ): ShippingOutcome {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return ShippingOutcome::failed( 'not_found', "No fulfillment exists with id {$fulfillment_id}." );
		}

		$now      = $this->clock->now();
		$shipment = Shipment::create( $fulfillment_id, $now );
		$shipment = Shipment::from_array( array( 'id' => $this->shipments->insert( $shipment ) ) + $shipment->to_array() );

		$package    = Package::create( $shipment->id(), 1, $now );
		$package_id = $this->packages->insert( $package );

		$allocations = array();

		foreach ( $this->items->find_for_fulfillment( $fulfillment_id ) as $item ) {
			if ( $item->qty_packed() > 0 ) {
				$allocations[] = PackageItem::create( $package_id, (int) $item->id(), $item->qty_packed() );
			}
		}

		if ( array() !== $allocations ) {
			$this->package_items->insert_all( $allocations );
		}

		if ( ! $this->fulfillments->touch( $fulfillment_id, $fulfillment->version() ) ) {
			return ShippingOutcome::failed( 'version_conflict', 'Someone else updated this fulfillment. Reload and try again.' );
		}

		$this->record_event( $fulfillment_id, 'shipment.created', $actor, $now, array( 'shipment_id' => $shipment->id() ) );
		$this->record_event(
			$fulfillment_id,
			'package.created',
			$actor,
			$now,
			array(
				'package_id'  => $package_id,
				'shipment_id' => $shipment->id(),
				'seq'         => 1,
			)
		);

		return ShippingOutcome::succeeded( $this->shipments->find( $shipment->id() ) );
	}

	/**
	 * Updates a shipment's carrier and tracking.
	 *
	 * @param int         $shipment_id     Shipment to update.
	 * @param string      $carrier_id      Carrier registry key.
	 * @param string      $service         Carrier service name.
	 * @param string|null $tracking_number Tracking number, or null to clear it.
	 * @param string|null $tracking_url    Explicit tracking-URL override, or null.
	 * @param Actor       $actor           Who is updating it.
	 */
	public function update_shipment(
		int $shipment_id,
		string $carrier_id,
		string $service,
		?string $tracking_number,
		?string $tracking_url,
		Actor $actor
	): ShippingOutcome {
		$shipment = $this->shipments->find( $shipment_id );

		if ( null === $shipment ) {
			return ShippingOutcome::failed( 'not_found', "No shipment exists with id {$shipment_id}." );
		}

		$fulfillment = $this->fulfillments->find( $shipment->fulfillment_id() );

		if ( null === $fulfillment ) {
			return ShippingOutcome::failed( 'not_found', 'The owning fulfillment no longer exists.' );
		}

		$shipment->set_carrier( $carrier_id, $service );
		$shipment->set_tracking( TrackingReference::create( $tracking_number, $tracking_url ) );
		$this->shipments->save( $shipment );

		if ( ! $this->fulfillments->touch( $fulfillment->id(), $fulfillment->version() ) ) {
			return ShippingOutcome::failed( 'version_conflict', 'Someone else updated this fulfillment. Reload and try again.' );
		}

		$this->record_event(
			$shipment->fulfillment_id(),
			'shipment.updated',
			$actor,
			$this->clock->now(),
			array(
				'shipment_id'     => $shipment_id,
				'carrier_id'      => $carrier_id,
				'service'         => $service,
				'tracking_number' => $tracking_number,
			)
		);

		return ShippingOutcome::succeeded( $shipment );
	}

	/**
	 * Deletes a shipment outright. Refused once it has shipped — a shipped
	 * shipment is corrected, never deleted (Architecture Plan §IV.6).
	 *
	 * @param int   $shipment_id Shipment to delete.
	 * @param Actor $actor       Who is deleting it.
	 */
	public function delete_shipment( int $shipment_id, Actor $actor ): ShippingOutcome {
		$shipment = $this->shipments->find( $shipment_id );

		if ( null === $shipment ) {
			return ShippingOutcome::failed( 'not_found', "No shipment exists with id {$shipment_id}." );
		}

		if ( ! $shipment->is_deletable() ) {
			return ShippingOutcome::failed( 'not_deletable', 'A shipped shipment cannot be deleted — it can only be corrected.' );
		}

		$fulfillment = $this->fulfillments->find( $shipment->fulfillment_id() );

		foreach ( $this->packages->find_for_shipment( $shipment_id ) as $package ) {
			$this->package_items->delete_for_package( (int) $package->id() );
			$this->packages->delete( (int) $package->id() );
		}

		$this->shipments->delete( $shipment_id );

		if ( null !== $fulfillment && ! $this->fulfillments->touch( $fulfillment->id(), $fulfillment->version() ) ) {
			return ShippingOutcome::failed( 'version_conflict', 'Someone else updated this fulfillment. Reload and try again.' );
		}

		$this->record_event( $shipment->fulfillment_id(), 'shipment.deleted', $actor, $this->clock->now(), array( 'shipment_id' => $shipment_id ) );

		return ShippingOutcome::succeeded();
	}

	/**
	 * Adds a new package to a shipment, at the next sequence number.
	 *
	 * @param int   $shipment_id Shipment to add a package to.
	 * @param Actor $actor       Who is adding it.
	 */
	public function add_package( int $shipment_id, Actor $actor ): ShippingOutcome {
		$shipment = $this->shipments->find( $shipment_id );

		if ( null === $shipment ) {
			return ShippingOutcome::failed( 'not_found', "No shipment exists with id {$shipment_id}." );
		}

		$fulfillment = $this->fulfillments->find( $shipment->fulfillment_id() );

		if ( null === $fulfillment ) {
			return ShippingOutcome::failed( 'not_found', 'The owning fulfillment no longer exists.' );
		}

		$seq        = $this->packages->next_seq_for_shipment( $shipment_id );
		$package    = Package::create( $shipment_id, $seq, $this->clock->now() );
		$package_id = $this->packages->insert( $package );

		if ( ! $this->fulfillments->touch( $fulfillment->id(), $fulfillment->version() ) ) {
			return ShippingOutcome::failed( 'version_conflict', 'Someone else updated this fulfillment. Reload and try again.' );
		}

		$this->record_event(
			$shipment->fulfillment_id(),
			'package.created',
			$actor,
			$this->clock->now(),
			array(
				'package_id'  => $package_id,
				'shipment_id' => $shipment_id,
				'seq'         => $seq,
			)
		);

		return ShippingOutcome::succeeded( $this->packages->find( $package_id ) );
	}

	/**
	 * Updates a package's dimensions and colli tracking number.
	 *
	 * @param int         $package_id      Package to update.
	 * @param int|null    $weight_grams    Weight in grams, or null.
	 * @param int|null    $length_mm       Length in millimetres, or null.
	 * @param int|null    $width_mm        Width in millimetres, or null.
	 * @param int|null    $height_mm       Height in millimetres, or null.
	 * @param string|null $tracking_number Colli tracking number, or null.
	 * @param Actor       $actor           Who is updating it.
	 */
	public function update_package(
		int $package_id,
		?int $weight_grams,
		?int $length_mm,
		?int $width_mm,
		?int $height_mm,
		?string $tracking_number,
		Actor $actor
	): ShippingOutcome {
		$package = $this->packages->find( $package_id );

		if ( null === $package ) {
			return ShippingOutcome::failed( 'not_found', "No package exists with id {$package_id}." );
		}

		$shipment = $this->shipments->find( $package->shipment_id() );

		if ( null === $shipment ) {
			return ShippingOutcome::failed( 'not_found', 'The owning shipment no longer exists.' );
		}

		$fulfillment = $this->fulfillments->find( $shipment->fulfillment_id() );

		if ( null === $fulfillment ) {
			return ShippingOutcome::failed( 'not_found', 'The owning fulfillment no longer exists.' );
		}

		$package->set_spec( PackageSpec::create( $weight_grams, $length_mm, $width_mm, $height_mm ) );
		$package->set_tracking_number( $tracking_number );
		$this->packages->save( $package );

		if ( ! $this->fulfillments->touch( $fulfillment->id(), $fulfillment->version() ) ) {
			return ShippingOutcome::failed( 'version_conflict', 'Someone else updated this fulfillment. Reload and try again.' );
		}

		$this->record_event(
			$shipment->fulfillment_id(),
			'package.updated',
			$actor,
			$this->clock->now(),
			array(
				'package_id'      => $package_id,
				'weight_grams'    => $weight_grams,
				'length_mm'       => $length_mm,
				'width_mm'        => $width_mm,
				'height_mm'       => $height_mm,
				'tracking_number' => $tracking_number,
			)
		);

		return ShippingOutcome::succeeded( $package );
	}

	/**
	 * Removes a package (and its line-quantity allocations) from its
	 * shipment.
	 *
	 * @param int   $package_id Package to remove.
	 * @param Actor $actor      Who is removing it.
	 */
	public function remove_package( int $package_id, Actor $actor ): ShippingOutcome {
		$package = $this->packages->find( $package_id );

		if ( null === $package ) {
			return ShippingOutcome::failed( 'not_found', "No package exists with id {$package_id}." );
		}

		$shipment    = $this->shipments->find( $package->shipment_id() );
		$fulfillment = null !== $shipment ? $this->fulfillments->find( $shipment->fulfillment_id() ) : null;

		$this->package_items->delete_for_package( $package_id );
		$this->packages->delete( $package_id );

		if ( null !== $fulfillment && ! $this->fulfillments->touch( $fulfillment->id(), $fulfillment->version() ) ) {
			return ShippingOutcome::failed( 'version_conflict', 'Someone else updated this fulfillment. Reload and try again.' );
		}

		if ( null !== $shipment ) {
			$this->record_event( $shipment->fulfillment_id(), 'package.deleted', $actor, $this->clock->now(), array( 'package_id' => $package_id ) );
		}

		return ShippingOutcome::succeeded();
	}

	/**
	 * Marks one shipment shipped.
	 *
	 * @param int   $shipment_id Shipment to ship.
	 * @param Actor $actor       Who is shipping it.
	 */
	public function ship( int $shipment_id, Actor $actor ): ShippingOutcome {
		$shipment = $this->shipments->find( $shipment_id );

		if ( null === $shipment ) {
			return ShippingOutcome::failed( 'not_found', "No shipment exists with id {$shipment_id}." );
		}

		$now = $this->clock->now();
		$shipment->mark_shipped( $now );
		$this->shipments->save( $shipment );

		$this->record_event( $shipment->fulfillment_id(), 'shipment.shipped', $actor, $now, array( 'shipment_id' => $shipment_id ) );

		return ShippingOutcome::succeeded( $shipment );
	}

	/**
	 * Marks every currently-`pending` shipment on a fulfillment shipped —
	 * what happens when the fulfillment itself transitions to `shipped`
	 * (Architecture Plan §IV.5.8 step 11). Wired to `fulfillment.state_changed`
	 * by a dedicated subscriber in the composition root, exactly like
	 * `Woo\StatusBridge` reacts to the same event for a different reason.
	 *
	 * @param int   $fulfillment_id Fulfillment whose pending shipments should ship.
	 * @param Actor $actor          Who is shipping them.
	 */
	public function ship_all_pending_for_fulfillment( int $fulfillment_id, Actor $actor ): void {
		foreach ( $this->shipments->find_for_fulfillment( $fulfillment_id ) as $shipment ) {
			if ( Shipment::STATUS_PENDING === $shipment->status() ) {
				$this->ship( (int) $shipment->id(), $actor );
			}
		}
	}

	/**
	 * Marks one shipment delivered.
	 *
	 * @param int   $shipment_id Shipment to mark delivered.
	 * @param Actor $actor       Who is marking it.
	 */
	public function mark_delivered( int $shipment_id, Actor $actor ): ShippingOutcome {
		$shipment = $this->shipments->find( $shipment_id );

		if ( null === $shipment ) {
			return ShippingOutcome::failed( 'not_found', "No shipment exists with id {$shipment_id}." );
		}

		$now = $this->clock->now();
		$shipment->mark_delivered( $now );
		$this->shipments->save( $shipment );

		$this->record_event( $shipment->fulfillment_id(), 'shipment.delivered', $actor, $now, array( 'shipment_id' => $shipment_id ) );

		return ShippingOutcome::succeeded( $shipment );
	}

	/**
	 * Marks one shipment as having a carrier-side exception.
	 *
	 * @param int   $shipment_id Shipment to flag.
	 * @param Actor $actor       Who is flagging it.
	 */
	public function mark_exception( int $shipment_id, Actor $actor ): ShippingOutcome {
		$shipment = $this->shipments->find( $shipment_id );

		if ( null === $shipment ) {
			return ShippingOutcome::failed( 'not_found', "No shipment exists with id {$shipment_id}." );
		}

		$shipment->mark_exception();
		$this->shipments->save( $shipment );

		$this->record_event( $shipment->fulfillment_id(), 'shipment.exception', $actor, $this->clock->now(), array( 'shipment_id' => $shipment_id ) );

		return ShippingOutcome::succeeded( $shipment );
	}

	/**
	 * Records a carrier-assigned label file path against a package — the
	 * seam a future carrier adapter (M12) attaches to; no label is ever
	 * generated here.
	 *
	 * @param int    $package_id Package to attach a label to.
	 * @param string $label_path Stored label file path.
	 * @param Actor  $actor      Who is attaching it.
	 */
	public function attach_label( int $package_id, string $label_path, Actor $actor ): ShippingOutcome {
		$package = $this->packages->find( $package_id );

		if ( null === $package ) {
			return ShippingOutcome::failed( 'not_found', "No package exists with id {$package_id}." );
		}

		$shipment = $this->shipments->find( $package->shipment_id() );
		$rebuilt  = Package::from_array( array( 'label_path' => $label_path ) + $package->to_array() );

		$this->packages->save( $rebuilt );

		if ( null !== $shipment ) {
			$this->record_event(
				$shipment->fulfillment_id(),
				'package.label_attached',
				$actor,
				$this->clock->now(),
				array(
					'package_id' => $package_id,
					'label_path' => $label_path,
				)
			);
		}

		return ShippingOutcome::succeeded( $rebuilt );
	}

	/**
	 * Appends one hash-chained audit event and dispatches it — the same
	 * shape {@see WorkflowService::record_events()} uses for the
	 * fulfillment's other half of the aggregate.
	 *
	 * @param int                  $fulfillment_id Fulfillment the event belongs to.
	 * @param string               $event_type     Event type to record.
	 * @param Actor                $actor          Who caused this event.
	 * @param \DateTimeImmutable   $now            When this event occurred.
	 * @param array<string, mixed> $payload        Event payload.
	 */
	private function record_event( int $fulfillment_id, string $event_type, Actor $actor, DateTimeImmutable $now, array $payload ): void {
		$event     = DomainEvent::for_fulfillment( $fulfillment_id, $event_type, $actor, $now, $payload );
		$prev_hash = $this->events->last_hash_for_fulfillment( $fulfillment_id );

		$this->events->append( $event, $prev_hash );
		$this->dispatcher->dispatch( $event );
	}
}
