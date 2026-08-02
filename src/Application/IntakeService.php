<?php
/**
 * Turns a paid order into a fulfillment — idempotently.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use DateTimeImmutable;
use MPCF\Domain\Clock;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\OrderLineSnapshot;
use MPCF\Domain\OrderSnapshot;
use MPCF\Domain\OrderSource;
use MPCF\Domain\Repository\EventRepository;
use MPCF\Domain\Repository\FulfillmentItemRepository;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Domain\Workflow\WorkflowDefinition;

/**
 * The same order must never produce more than one fulfillment, no matter how
 * many times {@see intake()} is called for it — a duplicate payment
 * notification and an Action Scheduler retry are both normal operating
 * conditions, not error cases. Idempotency is enforced twice, deliberately:
 * an up-front {@see FulfillmentRepository::find_by_order_id()} check handles
 * the common case cheaply, and the `(order_id, order_source)` uniqueness
 * constraint behind {@see FulfillmentRepository::insert()} is the actual
 * correctness guarantee for the race that check cannot close (two concurrent
 * calls both passing the up-front check before either has inserted). Losing
 * that race is not a failure: it means another call just did this work, so
 * this call falls back to reading what the winner created.
 *
 * `warehouse_id` is hardcoded to `1` in Milestone 1 (no location hierarchy
 * exists yet); the governing workflow is fixed to whichever
 * {@see WorkflowDefinition} this instance is constructed with, one per
 * store — Milestone 1 has exactly one, the standard workflow.
 */
final class IntakeService {

	/**
	 * Reads the order being ingested.
	 *
	 * @var OrderSource
	 */
	private OrderSource $orders;

	/**
	 * Fulfillment persistence.
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
	 * The workflow every intake creates a fulfillment into.
	 *
	 * @var WorkflowDefinition
	 */
	private WorkflowDefinition $workflow;

	/**
	 * Builds the service.
	 *
	 * @param OrderSource               $orders       Reads the order being ingested.
	 * @param FulfillmentRepository     $fulfillments Fulfillment persistence.
	 * @param FulfillmentItemRepository $items        Line item persistence.
	 * @param EventRepository           $events       Audit log persistence.
	 * @param EventDispatcher           $dispatcher   In-process event dispatch.
	 * @param Clock                     $clock        Source of "now".
	 * @param WorkflowDefinition        $workflow     The workflow every intake creates a fulfillment into.
	 */
	public function __construct(
		OrderSource $orders,
		FulfillmentRepository $fulfillments,
		FulfillmentItemRepository $items,
		EventRepository $events,
		EventDispatcher $dispatcher,
		Clock $clock,
		WorkflowDefinition $workflow
	) {
		$this->orders       = $orders;
		$this->fulfillments = $fulfillments;
		$this->items        = $items;
		$this->events       = $events;
		$this->dispatcher   = $dispatcher;
		$this->clock        = $clock;
		$this->workflow     = $workflow;
	}

	/**
	 * Ingests one order. Safe to call any number of times for the same
	 * order id — every call after the first is a no-op that returns the
	 * fulfillment already created.
	 *
	 * @param int $order_id Order to ingest.
	 */
	public function intake( int $order_id ): IntakeOutcome {
		$existing = $this->fulfillments->find_by_order_id( $order_id );

		if ( null !== $existing ) {
			return IntakeOutcome::already_existed( $existing );
		}

		$order = $this->orders->find( $order_id );

		if ( null === $order ) {
			return IntakeOutcome::failed( 'order_not_found', "No order exists with id {$order_id}." );
		}

		$now = $this->clock->now();

		$fulfillment = Fulfillment::intake(
			$order->order_id(),
			$order->order_source(),
			1,
			$this->workflow->name(),
			$this->workflow->initial_state(),
			$order->order_number(),
			$order->customer_name(),
			count( $order->items() ),
			$now
		);

		$id = $this->fulfillments->insert( $fulfillment );

		if ( null === $id ) {
			$winner = $this->fulfillments->find_by_order_id( $order_id );

			if ( null === $winner ) {
				return IntakeOutcome::failed( 'intake_race_unresolved', "Order {$order_id} was rejected by the uniqueness constraint but no existing fulfillment could be found afterward." );
			}

			return IntakeOutcome::already_existed( $winner );
		}

		$this->items->insert_all( $this->line_items( $id, $order ) );

		$this->record_created_event( $id, $order_id, $now );

		return IntakeOutcome::created( Fulfillment::from_array( array( 'id' => $id ) + $fulfillment->to_array() ) );
	}

	/**
	 * Builds the line items an order snapshot produces for one fulfillment.
	 *
	 * @param int           $fulfillment_id Owning fulfillment's id.
	 * @param OrderSnapshot $order          Order the items are read from.
	 * @return array<int, FulfillmentItem>
	 */
	private function line_items( int $fulfillment_id, OrderSnapshot $order ): array {
		return array_map(
			static fn( OrderLineSnapshot $line ): FulfillmentItem => FulfillmentItem::intake(
				$fulfillment_id,
				$line->order_item_id(),
				$line->product_id(),
				$line->variation_id(),
				$line->sku(),
				$line->name(),
				$line->quantity()
			),
			$order->items()
		);
	}

	/**
	 * Appends the audit event marking a fulfillment's creation. Payload is
	 * deliberately minimal — just the order reference, per the PII payload
	 * guard's design intent (invariant covered by {@see \MPCF\Domain\Event\PayloadGuard}):
	 * copy only what the architecture explicitly requires, never a
	 * questionable field "while we're here".
	 *
	 * @param int               $fulfillment_id Fulfillment just created.
	 * @param int               $order_id       Order it was created from.
	 * @param DateTimeImmutable $now            When this happened.
	 */
	private function record_created_event( int $fulfillment_id, int $order_id, DateTimeImmutable $now ): void {
		$event     = DomainEvent::for_fulfillment( $fulfillment_id, 'fulfillment.created', Actor::system(), $now, array( 'order_id' => $order_id ) );
		$prev_hash = $this->events->last_hash_for_fulfillment( $fulfillment_id );

		$this->events->append( $event, $prev_hash );
		$this->dispatcher->dispatch( $event );
	}
}
