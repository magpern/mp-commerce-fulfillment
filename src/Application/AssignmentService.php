<?php
/**
 * Application-layer facade for fulfillment assignment.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use MPCF\Domain\Clock;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\Repository\EventRepository;
use MPCF\Domain\Repository\FulfillmentRepository;

/**
 * Assignment (`assignee_type`/`assignee_id`) is plain fulfillment metadata,
 * not a workflow transition — it never touches {@see WorkflowService} or
 * the engine, and is never itself guard-checked. A failed
 * {@see FulfillmentRepository::save()} (a concurrent edit lost the
 * optimistic-lock race) is reported back as `false`, the same "partial
 * failure per row" shape the Queue's bulk actions need — never an
 * exception, and never a direct write from Admin (invariant I11,
 * `AdminBoundaryGuardTest`).
 *
 * Every successful assignment/unassignment is audited (§IV.5.1's soft
 * claim: opening the Packing Workspace on an unassigned fulfillment
 * self-assigns it, and taking over someone else's claim reassigns it —
 * both "audited" by the plan's own wording, which is why this class
 * carries an event pipeline the Queue's bulk-assign action also now
 * benefits from, not only the workspace).
 */
final class AssignmentService {

	/**
	 * Fulfillment persistence.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

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
	 * @param FulfillmentRepository $fulfillments Fulfillment persistence.
	 * @param EventRepository       $events       Audit log persistence.
	 * @param EventDispatcher       $dispatcher   In-process event dispatch.
	 * @param Clock                 $clock        Source of "now".
	 */
	public function __construct( FulfillmentRepository $fulfillments, EventRepository $events, EventDispatcher $dispatcher, Clock $clock ) {
		$this->fulfillments = $fulfillments;
		$this->events       = $events;
		$this->dispatcher   = $dispatcher;
		$this->clock        = $clock;
	}

	/**
	 * Assigns a fulfillment to a user.
	 *
	 * @param int   $fulfillment_id Fulfillment to assign.
	 * @param int   $user_id        User to assign it to.
	 * @param Actor $actor          Who is assigning it.
	 */
	public function assign( int $fulfillment_id, int $user_id, Actor $actor ): bool {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return false;
		}

		$fulfillment->assign( 'user', $user_id );

		if ( ! $this->fulfillments->save( $fulfillment ) ) {
			return false;
		}

		$this->record_event( $fulfillment_id, 'fulfillment.assigned', $actor, array( 'assignee_id' => $user_id ) );

		return true;
	}

	/**
	 * Clears a fulfillment's assignment.
	 *
	 * @param int   $fulfillment_id Fulfillment to unassign.
	 * @param Actor $actor          Who is unassigning it.
	 */
	public function unassign( int $fulfillment_id, Actor $actor ): bool {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return false;
		}

		$fulfillment->unassign();

		if ( ! $this->fulfillments->save( $fulfillment ) ) {
			return false;
		}

		$this->record_event( $fulfillment_id, 'fulfillment.unassigned', $actor, array() );

		return true;
	}

	/**
	 * Appends one hash-chained audit event and dispatches it — the same
	 * shape {@see ShippingService::record_event()} uses.
	 *
	 * @param int                  $fulfillment_id Fulfillment the event belongs to.
	 * @param string               $event_type     Event type to record.
	 * @param Actor                $actor          Who caused this event.
	 * @param array<string, mixed> $payload        Event payload.
	 */
	private function record_event( int $fulfillment_id, string $event_type, Actor $actor, array $payload ): void {
		$now       = $this->clock->now();
		$event     = DomainEvent::for_fulfillment( $fulfillment_id, $event_type, $actor, $now, $payload );
		$prev_hash = $this->events->last_hash_for_fulfillment( $fulfillment_id );

		$this->events->append( $event, $prev_hash );
		$this->dispatcher->dispatch( $event );
	}
}
