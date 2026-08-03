<?php
/**
 * Batch picked/packed quantity updates for a fulfillment's line items.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use DateTimeImmutable;
use MPCF\Domain\Clock;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Repository\EventRepository;
use MPCF\Domain\Repository\FulfillmentItemRepository;
use MPCF\Domain\Repository\FulfillmentRepository;

/**
 * Architecture Plan §IV.5.8/§IV.9/§IV.10. The workspace debounces line
 * taps client-side and flushes one batch per burst; this service is what
 * turns that one batch into one atomic write. Quantities are always
 * absolute (never deltas), so a retried or double-submitted batch is
 * idempotent by construction.
 *
 * The fulfillment's `version` is checked and advanced *before* any item
 * row is touched — a loser of the optimistic-lock race is rejected with
 * nothing written, never a partial or silently-overwritten batch. This
 * mirrors the guarantee IV.5.7 states for the aggregate as a whole:
 * `FulfillmentRepository::touch()` is the one place `version` moves for
 * this half of the aggregate too, exactly as it is for
 * {@see ShippingService}'s shipment/package writes.
 */
final class PackingService {

	/**
	 * Fulfillment lookup, for the version check/advance.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

	/**
	 * Line item persistence.
	 *
	 * @var FulfillmentItemRepository
	 */
	private FulfillmentItemRepository $items;

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
	 * @param FulfillmentRepository     $fulfillments Fulfillment lookup, for the version check/advance.
	 * @param FulfillmentItemRepository $items        Line item persistence.
	 * @param EventRepository           $events       Audit log persistence.
	 * @param EventDispatcher           $dispatcher   In-process event dispatch.
	 * @param Clock                     $clock        Source of "now".
	 */
	public function __construct(
		FulfillmentRepository $fulfillments,
		FulfillmentItemRepository $items,
		EventRepository $events,
		EventDispatcher $dispatcher,
		Clock $clock
	) {
		$this->fulfillments = $fulfillments;
		$this->items        = $items;
		$this->events       = $events;
		$this->dispatcher   = $dispatcher;
		$this->clock        = $clock;
	}

	/**
	 * Applies one batch of absolute picked/packed quantities.
	 *
	 * @param int                                                        $fulfillment_id   Fulfillment the lines belong to.
	 * @param int                                                        $expected_version Caller-asserted current version.
	 * @param list<array{item_id:int, qty_picked?:int, qty_packed?:int}> $lines            Absolute quantities per line.
	 * @param Actor                                                      $actor            Who is applying the batch.
	 */
	public function update_quantities( int $fulfillment_id, int $expected_version, array $lines, Actor $actor ): PackingOutcome {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return PackingOutcome::failed( 'not_found', "No fulfillment exists with id {$fulfillment_id}." );
		}

		if ( array() === $lines ) {
			return PackingOutcome::failed( 'invalid_payload', 'A batch must contain at least one line.' );
		}

		$existing = array();

		foreach ( $this->items->find_for_fulfillment( $fulfillment_id ) as $item ) {
			$existing[ (int) $item->id() ] = $item;
		}

		$picked_changes = array();
		$packed_changes = array();
		$updated        = array();

		foreach ( $lines as $line ) {
			$item_id = (int) ( $line['item_id'] ?? 0 );

			if ( ! isset( $existing[ $item_id ] ) ) {
				return PackingOutcome::failed( 'invalid_payload', "Line references item {$item_id}, which does not belong to this fulfillment." );
			}

			if ( ! array_key_exists( 'qty_picked', $line ) && ! array_key_exists( 'qty_packed', $line ) ) {
				return PackingOutcome::failed( 'invalid_payload', "Line for item {$item_id} sets neither qty_picked nor qty_packed." );
			}

			$item    = $existing[ $item_id ];
			$changed = false;

			if ( array_key_exists( 'qty_picked', $line ) ) {
				$previous = $item->qty_picked();
				$item->record_picked( (int) $line['qty_picked'] );

				if ( $item->qty_picked() !== $previous ) {
					$picked_changes[] = array(
						'item_id'    => $item_id,
						'qty_picked' => $item->qty_picked(),
					);
					$changed          = true;
				}
			}

			if ( array_key_exists( 'qty_packed', $line ) ) {
				$previous = $item->qty_packed();
				$item->record_packed( (int) $line['qty_packed'] );

				if ( $item->qty_packed() !== $previous ) {
					$packed_changes[] = array(
						'item_id'    => $item_id,
						'qty_packed' => $item->qty_packed(),
					);
					$changed          = true;
				}
			}

			if ( $changed ) {
				$updated[ $item_id ] = $item;
			}
		}

		if ( array() === $updated ) {
			$unchanged = array();

			foreach ( $lines as $line ) {
				$item_id = (int) ( $line['item_id'] ?? 0 );

				if ( isset( $existing[ $item_id ] ) ) {
					$unchanged[] = $existing[ $item_id ];
				}
			}

			return PackingOutcome::succeeded( $unchanged, $fulfillment->version() );
		}

		if ( ! $this->fulfillments->touch( $fulfillment_id, $expected_version ) ) {
			return PackingOutcome::failed( 'version_conflict', 'Someone else updated this fulfillment. Reload and try again.' );
		}

		foreach ( $updated as $item ) {
			$this->items->save( $item );
		}

		$now = $this->clock->now();

		if ( array() !== $picked_changes ) {
			$this->record_event( $fulfillment_id, 'items.picked', $actor, $now, array( 'lines' => $picked_changes ) );
		}

		if ( array() !== $packed_changes ) {
			$this->record_event( $fulfillment_id, 'items.packed', $actor, $now, array( 'lines' => $packed_changes ) );
		}

		return PackingOutcome::succeeded( array_values( $updated ), $expected_version + 1 );
	}

	/**
	 * Sets every line's picked (or packed) quantity to its full ordered
	 * quantity in one batch — the `Complete all` action (Architecture Plan
	 * §IV.5.2).
	 *
	 * @param int    $fulfillment_id   Fulfillment to complete.
	 * @param int    $expected_version Caller-asserted current version.
	 * @param string $field            Either `qty_picked` or `qty_packed`.
	 * @param Actor  $actor            Who is completing it.
	 */
	public function complete_all( int $fulfillment_id, int $expected_version, string $field, Actor $actor ): PackingOutcome {
		if ( 'qty_picked' !== $field && 'qty_packed' !== $field ) {
			return PackingOutcome::failed( 'invalid_payload', 'field must be either qty_picked or qty_packed.' );
		}

		if ( null === $this->fulfillments->find( $fulfillment_id ) ) {
			return PackingOutcome::failed( 'not_found', "No fulfillment exists with id {$fulfillment_id}." );
		}

		$fulfillment_items = $this->items->find_for_fulfillment( $fulfillment_id );

		if ( array() === $fulfillment_items ) {
			return PackingOutcome::failed( 'invalid_payload', 'This fulfillment has no line items to complete.' );
		}

		$lines = array();

		foreach ( $fulfillment_items as $item ) {
			$lines[] = array(
				'item_id' => (int) $item->id(),
				$field    => $item->qty_ordered(),
			);
		}

		return $this->update_quantities( $fulfillment_id, $expected_version, $lines, $actor );
	}

	/**
	 * Appends one hash-chained audit event and dispatches it — the same
	 * shape {@see ShippingService::record_event()} uses.
	 *
	 * @param int                  $fulfillment_id Fulfillment the event belongs to.
	 * @param string               $event_type     Event type to record.
	 * @param Actor                $actor          Who caused this event.
	 * @param DateTimeImmutable    $now            When this event occurred.
	 * @param array<string, mixed> $payload        Event payload.
	 */
	private function record_event( int $fulfillment_id, string $event_type, Actor $actor, DateTimeImmutable $now, array $payload ): void {
		$event     = DomainEvent::for_fulfillment( $fulfillment_id, $event_type, $actor, $now, $payload );
		$prev_hash = $this->events->last_hash_for_fulfillment( $fulfillment_id );

		$this->events->append( $event, $prev_hash );
		$this->dispatcher->dispatch( $event );
	}
}
