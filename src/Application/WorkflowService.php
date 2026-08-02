<?php
/**
 * The sole writer of fulfillment state.
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
use MPCF\Domain\Workflow\WorkflowDefinition;
use MPCF\Engine\TransitionContext;
use MPCF\Engine\WorkflowEngine;

/**
 * Invariant I4: every state mutation flows through this class — no other
 * code path writes `mpcf_fulfillments.state`. This class:
 *
 * 1. loads the fulfillment and its items (the only guard-relevant data
 *    this layer knows how to source itself; package/shipment/photo flags
 *    are supplied by the caller — see {@see transition()});
 * 2. asks {@see WorkflowEngine} whether the request is approved;
 * 3. on approval, records the transition on the in-memory fulfillment and
 *    persists it through the optimistic lock;
 * 4. on a successful persist, appends one hash-chained audit event per
 *    event type the edge declared, and dispatches each to
 *    {@see EventDispatcher}.
 *
 * Capability enforcement is deliberately not this class's job — checking
 * whether the acting user holds a capability is a platform concern, and
 * this class (Application, invariant I6) stays platform-free exactly like
 * Domain and Engine. Every entry point (admin screens, and later the REST
 * API) checks its own capability before ever calling here; both consuming
 * the same service is what invariant I11 means.
 */
final class WorkflowService {

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
	 * Pure transition decision logic.
	 *
	 * @var WorkflowEngine
	 */
	private WorkflowEngine $engine;

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
	 * Registered workflow definitions, keyed by {@see WorkflowDefinition::name()}.
	 *
	 * @var array<string, WorkflowDefinition>
	 */
	private array $definitions;

	/**
	 * Builds the service.
	 *
	 * @param FulfillmentRepository             $fulfillments Fulfillment persistence.
	 * @param FulfillmentItemRepository         $items        Line item persistence.
	 * @param EventRepository                   $events       Audit log persistence.
	 * @param WorkflowEngine                    $engine       Pure transition decision logic.
	 * @param EventDispatcher                   $dispatcher   In-process event dispatch.
	 * @param Clock                             $clock        Source of "now".
	 * @param array<string, WorkflowDefinition> $definitions  Registered workflow definitions, keyed by name.
	 */
	public function __construct(
		FulfillmentRepository $fulfillments,
		FulfillmentItemRepository $items,
		EventRepository $events,
		WorkflowEngine $engine,
		EventDispatcher $dispatcher,
		Clock $clock,
		array $definitions
	) {
		$this->fulfillments = $fulfillments;
		$this->items        = $items;
		$this->events       = $events;
		$this->engine       = $engine;
		$this->dispatcher   = $dispatcher;
		$this->clock        = $clock;
		$this->definitions  = $definitions;
	}

	/**
	 * Attempts to move a fulfillment to a target state.
	 *
	 * `$package_spec_present`, `$has_shipment` and
	 * `$photo_requirement_satisfied` are manual operator confirmation in
	 * Milestone 1 (there is no package/shipment data model yet — see
	 * {@see \MPCF\Engine\TransitionContext}); a future milestone's caller
	 * supplies them from real data instead, with no change needed here.
	 *
	 * @param int         $fulfillment_id               Fulfillment to transition.
	 * @param string      $target_state                 State being requested.
	 * @param Actor       $actor                        Who is requesting this transition.
	 * @param string|null $reason                       Reason text, required by some edges (guard-independent — the Engine layer does not enforce this; the caller is expected to have collected it whenever {@see \MPCF\Domain\Workflow\Transition::requires_reason()} is true).
	 * @param bool        $package_spec_present         Whether a package spec has been confirmed.
	 * @param bool        $has_shipment                 Whether a shipment has been confirmed.
	 * @param bool        $photo_requirement_satisfied  Whether the photo-required setting is satisfied.
	 */
	public function transition(
		int $fulfillment_id,
		string $target_state,
		Actor $actor,
		?string $reason = null,
		bool $package_spec_present = false,
		bool $has_shipment = false,
		bool $photo_requirement_satisfied = true
	): TransitionOutcome {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return TransitionOutcome::failed( 'fulfillment_not_found', "No fulfillment exists with id {$fulfillment_id}." );
		}

		$definition = $this->definitions[ $fulfillment->workflow() ] ?? null;

		if ( null === $definition ) {
			return TransitionOutcome::failed( 'unknown_workflow', "Workflow \"{$fulfillment->workflow()}\" is not registered." );
		}

		$context = new TransitionContext(
			$this->items->find_for_fulfillment( $fulfillment_id ),
			$package_spec_present,
			$has_shipment,
			$photo_requirement_satisfied
		);

		$result = $this->engine->transition( $fulfillment, $target_state, $definition, $context );

		if ( ! $result->is_approved() ) {
			return TransitionOutcome::failed( (string) $result->rejection_code(), (string) $result->rejection_message() );
		}

		$previous_state = $fulfillment->state();
		$now            = $this->clock->now();

		$fulfillment->apply_transition( $result->new_state(), $result->entering_exception_from(), $now );

		if ( null !== $reason ) {
			$fulfillment->set_exception_reason( $reason );
		}

		if ( ! $this->fulfillments->save( $fulfillment ) ) {
			return TransitionOutcome::failed( 'version_conflict', 'Someone else updated this fulfillment. Reload and try again.' );
		}

		$payload = array(
			'from' => $previous_state,
			'to'   => $fulfillment->state(),
		);

		if ( null !== $reason ) {
			$payload['reason'] = $reason;
		}

		$this->record_events( $fulfillment->id(), $result->events(), $actor, $now, $payload );

		return TransitionOutcome::succeeded( $fulfillment );
	}

	/**
	 * Appends one hash-chained audit event per declared event type, and
	 * dispatches each to {@see EventDispatcher}.
	 *
	 * @param int                  $fulfillment_id Fulfillment the events belong to.
	 * @param array<int, string>   $event_types    Event types to record.
	 * @param Actor                $actor          Who caused these events.
	 * @param DateTimeImmutable    $now            When these events occurred.
	 * @param array<string, mixed> $payload        Shared payload for every event recorded.
	 */
	private function record_events( int $fulfillment_id, array $event_types, Actor $actor, DateTimeImmutable $now, array $payload ): void {
		foreach ( $event_types as $event_type ) {
			$event     = DomainEvent::for_fulfillment( $fulfillment_id, $event_type, $actor, $now, $payload );
			$prev_hash = $this->events->last_hash_for_fulfillment( $fulfillment_id );

			$this->events->append( $event, $prev_hash );
			$this->dispatcher->dispatch( $event );
		}
	}
}
