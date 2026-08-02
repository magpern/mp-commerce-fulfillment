<?php
/**
 * Decides whether a fulfillment may move to a target state.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine;

use MPCF\Domain\Fulfillment;
use MPCF\Domain\Workflow\WorkflowDefinition;

/**
 * Pure decision logic: validates the edge exists (or is the one dynamic
 * edge this class itself resolves — see below), runs that edge's guards in
 * order, and reports the outcome. This class:
 *
 * - persists nothing;
 * - touches no direct database access and no platform function;
 * - names no platform-integration symbol;
 * - dispatches no event and writes no audit entry;
 * - has no side effect of any kind.
 *
 * Everything it decides is reported back as a {@see TransitionResult} for
 * the Application layer (`WorkflowService`) to act on — persisting the new
 * state, dispatching the declared events, and appending the audit entry
 * are all that class's job, never this one's.
 *
 * **Exception resolution lives here, not in `WorkflowDefinition`.** Moving
 * from an exception state back to whatever state it interrupted is not a
 * fact about the workflow graph — the target is one specific fulfillment's
 * `return_to_state`, which only this per-attempt decision has access to.
 * {@see resolve_exception_edge()} checks that dynamic case before
 * consulting the static graph at all.
 */
final class WorkflowEngine {

	/**
	 * Resolves a transition's guard ids to real guards.
	 *
	 * @var GuardRegistry
	 */
	private GuardRegistry $guards;

	/**
	 * Builds the engine.
	 *
	 * @param GuardRegistry $guards Resolves a transition's guard ids to real guards.
	 */
	public function __construct( GuardRegistry $guards ) {
		$this->guards = $guards;
	}

	/**
	 * Decides whether `$fulfillment` may move to `$target_state` under
	 * `$definition`, given `$context`. Never mutates anything it is given.
	 *
	 * @param Fulfillment        $fulfillment   Fulfillment being transitioned. Read only — never mutated.
	 * @param string             $target_state  State being requested.
	 * @param WorkflowDefinition $definition    The workflow governing `$fulfillment`.
	 * @param TransitionContext  $context       Guard-relevant data for this attempt.
	 */
	public function transition(
		Fulfillment $fulfillment,
		string $target_state,
		WorkflowDefinition $definition,
		TransitionContext $context
	): TransitionResult {
		if ( ! $definition->has_state( $fulfillment->state() ) ) {
			return TransitionResult::rejected(
				'unknown_current_state',
				"Fulfillment is in state \"{$fulfillment->state()}\", which \"{$definition->name()}\" does not declare."
			);
		}

		if ( ! $definition->has_state( $target_state ) ) {
			return TransitionResult::rejected(
				'unknown_target_state',
				"\"{$target_state}\" is not a state \"{$definition->name()}\" declares."
			);
		}

		$resolution = $this->resolve_exception_edge( $fulfillment, $target_state, $definition );

		if ( null !== $resolution ) {
			return $resolution;
		}

		$transition = $definition->transition( $fulfillment->state(), $target_state );

		if ( null === $transition ) {
			return TransitionResult::rejected(
				'no_such_edge',
				"\"{$fulfillment->state()}\" -> \"{$target_state}\" is not an allowed transition in \"{$definition->name()}\"."
			);
		}

		foreach ( $transition->guards() as $guard_id ) {
			$guard = $this->guards->get( $guard_id );

			if ( ! $guard->is_satisfied( $fulfillment, $context ) ) {
				return TransitionResult::rejected( $guard_id, $guard->unmet_reason() );
			}
		}

		$entering_exception_from = $definition->state( $target_state )->is_exception() ? $fulfillment->state() : null;

		return TransitionResult::approved( $target_state, $entering_exception_from, $transition->events() );
	}

	/**
	 * The one dynamic edge this class resolves outside the static graph:
	 * leaving an exception state back to the working state it interrupted.
	 * No guards apply to a resolution — the exception itself was the
	 * guard-equivalent event that got the fulfillment here.
	 *
	 * @param Fulfillment        $fulfillment  Fulfillment being transitioned.
	 * @param string             $target_state State being requested.
	 * @param WorkflowDefinition $definition   The workflow governing `$fulfillment`.
	 */
	private function resolve_exception_edge( Fulfillment $fulfillment, string $target_state, WorkflowDefinition $definition ): ?TransitionResult {
		if ( ! $definition->state( $fulfillment->state() )->is_exception() ) {
			return null;
		}

		if ( $target_state !== $fulfillment->return_to_state() ) {
			return null;
		}

		return TransitionResult::approved( $target_state, null, array( 'fulfillment.state_changed' ) );
	}
}
