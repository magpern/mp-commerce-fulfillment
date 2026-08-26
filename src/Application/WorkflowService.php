<?php
/**
 * The sole writer of fulfillment state.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use MPCF\Capabilities;
use MPCF\Domain\Clock;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\Event\PayloadGuard;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\Repository\EventRepository;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Domain\Workflow\WorkflowDefinition;
use MPCF\Engine\WorkflowEngine;

/**
 * Invariant I4: every state mutation flows through this class — no other
 * code path writes `mpcf_fulfillments.state`. This class:
 *
 * 1. loads the fulfillment and asks {@see TransitionContextFactory} for
 *    the rest of the guard-relevant data, all derived from real
 *    persisted state (Architecture Plan §IV.3.B, findings B/C/D — no
 *    caller-asserted boolean has been accepted here since Milestone 2);
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
 * the same service is what invariant I11 means. {@see available_transitions()}
 * accepts the capability predicate as a plain `callable` for the same
 * reason — this class asks "is the edge approved", never "may this actor
 * take it".
 */
final class WorkflowService {

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
	 * Builds real-data transition contexts.
	 *
	 * @var TransitionContextFactory
	 */
	private TransitionContextFactory $context_factory;

	/**
	 * Builds the service.
	 *
	 * @param FulfillmentRepository             $fulfillments    Fulfillment persistence.
	 * @param EventRepository                   $events          Audit log persistence.
	 * @param WorkflowEngine                    $engine          Pure transition decision logic.
	 * @param EventDispatcher                   $dispatcher      In-process event dispatch.
	 * @param Clock                             $clock           Source of "now".
	 * @param array<string, WorkflowDefinition> $definitions     Registered workflow definitions, keyed by name.
	 * @param TransitionContextFactory          $context_factory Builds real-data transition contexts.
	 */
	public function __construct(
		FulfillmentRepository $fulfillments,
		EventRepository $events,
		WorkflowEngine $engine,
		EventDispatcher $dispatcher,
		Clock $clock,
		array $definitions,
		TransitionContextFactory $context_factory
	) {
		$this->fulfillments    = $fulfillments;
		$this->events          = $events;
		$this->engine          = $engine;
		$this->dispatcher      = $dispatcher;
		$this->clock           = $clock;
		$this->definitions     = $definitions;
		$this->context_factory = $context_factory;
	}

	/**
	 * Attempts to move a fulfillment to a target state. Guard-relevant data
	 * beyond the fulfillment itself comes from {@see TransitionContextFactory},
	 * built from real shipment/package/item state — never a caller-asserted
	 * boolean (Architecture Plan §IV.3.B, findings B/C/D).
	 *
	 * @param int         $fulfillment_id Fulfillment to transition.
	 * @param string      $target_state   State being requested.
	 * @param Actor       $actor          Who is requesting this transition.
	 * @param string|null $reason         Reason text, required by some edges (guard-independent — the Engine layer does not enforce this; the caller is expected to have collected it whenever {@see \MPCF\Domain\Workflow\Transition::requires_reason()} is true).
	 */
	public function transition(
		int $fulfillment_id,
		string $target_state,
		Actor $actor,
		?string $reason = null
	): TransitionOutcome {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return TransitionOutcome::failed( 'fulfillment_not_found', "No fulfillment exists with id {$fulfillment_id}." );
		}

		$definition = $this->definitions[ $fulfillment->workflow() ] ?? null;

		if ( null === $definition ) {
			return TransitionOutcome::failed( 'unknown_workflow', "Workflow \"{$fulfillment->workflow()}\" is not registered." );
		}

		$context = $this->context_factory->build( $fulfillment_id );

		$result = $this->engine->transition( $fulfillment, $target_state, $definition, $context );

		if ( ! $result->is_approved() ) {
			return TransitionOutcome::failed( (string) $result->rejection_code(), (string) $result->rejection_message() );
		}

		$previous_state = $fulfillment->state();
		$now            = $this->clock->now();

		$payload = array(
			'from' => $previous_state,
			'to'   => $result->new_state(),
		);

		if ( null !== $reason ) {
			$payload['reason'] = $reason;
		}

		// Validated before anything is persisted: an unsafe payload must
		// reject the whole transition, never leave the state change saved
		// with the audit entry that was supposed to accompany it missing
		// (invariant I10 — never break the shop with an uncaught exception
		// mid-write).
		try {
			PayloadGuard::assert_safe( $payload );
		} catch ( InvalidArgumentException $exception ) {
			return TransitionOutcome::failed( 'unsafe_event_payload', $exception->getMessage() );
		}

		$fulfillment->apply_transition( $result->new_state(), $result->entering_exception_from(), $now );

		if ( null !== $reason ) {
			$fulfillment->set_exception_reason( $reason );
		}

		if ( ! $this->fulfillments->save( $fulfillment ) ) {
			return TransitionOutcome::failed( 'version_conflict', 'Someone else updated this fulfillment. Reload and try again.' );
		}

		$this->record_events( $fulfillment->id(), $result->events(), $actor, $now, $payload );

		// Public lifecycle action: emit only after save + internal audit/events
		// succeeded. Listener failures must not flip a durable success into a
		// failed TransitionOutcome (host adapters catch their own errors too).
		$this->emit_state_changed( $fulfillment, $previous_state, $now );

		return TransitionOutcome::succeeded( $fulfillment );
	}

	/**
	 * Every candidate next state for a fulfillment, with its real
	 * eligibility — the one rule source {@see \MPCF\Admin\FulfillmentDetailPage},
	 * the Packing Workspace and `GET /mpcf/v1/fulfillments/{id}/transitions`
	 * all render from (Architecture Plan §IV.3.B). Candidates are every
	 * statically declared edge from the fulfillment's current state, plus
	 * — when that state is an exception state — the dynamic edge back to
	 * `return_to_state()` {@see WorkflowEngine} resolves at attempt time.
	 * A candidate whose required capability `$can` rejects is omitted
	 * entirely, never shown as a disabled control.
	 *
	 * @param int      $fulfillment_id Fulfillment to list candidates for.
	 * @param callable $can            Capability predicate: `fn(string $capability): bool`.
	 * @return list<AvailableTransition>
	 */
	public function available_transitions( int $fulfillment_id, callable $can ): array {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return array();
		}

		$definition = $this->definitions[ $fulfillment->workflow() ] ?? null;

		if ( null === $definition || ! $definition->has_state( $fulfillment->state() ) ) {
			return array();
		}

		$context = $this->context_factory->build( $fulfillment_id );

		$targets = array_map(
			static fn( $transition ) => $transition->to(),
			$definition->transitions_from( $fulfillment->state() )
		);

		if ( $definition->state( $fulfillment->state() )->is_exception() && null !== $fulfillment->return_to_state() ) {
			$targets[] = $fulfillment->return_to_state();
		}

		$available = array();

		foreach ( array_unique( $targets ) as $target ) {
			$transition = $definition->transition( $fulfillment->state(), $target );
			$capability = null !== $transition ? $transition->required_capability() : Capabilities::PROCESS_FULFILLMENTS;

			if ( ! $can( $capability ) ) {
				continue;
			}

			$result = $this->engine->transition( $fulfillment, $target, $definition, $context );
			$label  = $definition->has_state( $target ) ? $definition->state( $target )->label() : $target;

			$available[] = AvailableTransition::from_result( $target, $label, $result, null !== $transition && $transition->requires_reason(), $capability );
		}

		return $available;
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

	/**
	 * Emits the public WordPress lifecycle action after a fully successful
	 * transition (save + record_events). Never throws out of this method.
	 *
	 * @param Fulfillment       $fulfillment    Persisted fulfillment.
	 * @param string            $previous_state State before this transition.
	 * @param DateTimeImmutable $now            Transition timestamp.
	 */
	private function emit_state_changed( Fulfillment $fulfillment, string $previous_state, DateTimeImmutable $now ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		$payload = array(
			'fulfillment_id' => (int) $fulfillment->id(),
			'order_id'       => (int) $fulfillment->order_id(),
			'from_state'     => $previous_state,
			'to_state'       => (string) $fulfillment->state(),
			'occurred_at'    => (int) $now->getTimestamp(),
			'source'         => 'workflow',
		);

		try {
			/**
			 * Fires after a fulfillment state transition has been fully persisted
			 * (save + internal audit/event recording). Integrators must catch
			 * their own listener failures.
			 *
			 * @param array{
			 *     fulfillment_id:int,
			 *     order_id:int,
			 *     from_state:string,
			 *     to_state:string,
			 *     occurred_at:int,
			 *     source:string
			 * } $payload Lifecycle payload.
			 */
			do_action( 'mpcf_fulfillment_state_changed', $payload );
		} catch ( \Throwable $e ) {
			$message = 'MPCF mpcf_fulfillment_state_changed listener failure: ' . substr( $e->getMessage(), 0, 160 );
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( $message, array( 'source' => 'mpcf-lifecycle' ) );
			} elseif ( function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Listener isolation breadcrumb when WC logger unavailable.
				error_log( $message );
			}
		}
	}
}
