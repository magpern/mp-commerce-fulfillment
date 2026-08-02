<?php
/**
 * Contract for one named transition eligibility predicate.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine;

use MPCF\Domain\Fulfillment;

/**
 * A guard evaluates eligibility only — it must never mutate `$fulfillment`,
 * write anything, or have any side effect. {@see WorkflowEngine} is the
 * only caller; it treats every guard as a pure function of the fulfillment
 * and the transition context.
 */
interface TransitionGuard {

	/**
	 * The identifier a {@see \MPCF\Domain\Workflow\Transition} names this
	 * guard by.
	 */
	public function id(): string;

	/**
	 * Whether this guard's condition is currently met.
	 *
	 * @param Fulfillment       $fulfillment The fulfillment being transitioned.
	 * @param TransitionContext $context     Guard-relevant data for this attempt.
	 */
	public function is_satisfied( Fulfillment $fulfillment, TransitionContext $context ): bool;

	/**
	 * Human-readable reason to show when this guard blocks a transition.
	 */
	public function unmet_reason(): string;
}
