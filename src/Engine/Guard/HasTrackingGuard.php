<?php
/**
 * Guard: the tracking-required setting, if any, is satisfied.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\Guard;

use MPCF\Domain\Fulfillment;
use MPCF\Engine\TransitionContext;
use MPCF\Engine\TransitionGuard;

/**
 * Blocks `packed -> shipped` when the `require_tracking_before_ship`
 * setting (Architecture Plan §IV.15/F21) is on and no tracking number has
 * been recorded. This guard never reads the setting itself — Engine stays
 * WordPress-free (I6) — its condition is pre-resolved by
 * {@see \MPCF\Application\TransitionContextFactory} into
 * `TransitionContext::tracking_requirement_satisfied()`, satisfied
 * whenever the setting is off or any shipment already has a tracking
 * number.
 */
final class HasTrackingGuard implements TransitionGuard {

	/**
	 * The guard identifier this class implements.
	 */
	public function id(): string {
		return 'has_tracking';
	}

	/**
	 * Whether this guard's condition is currently met.
	 *
	 * @param Fulfillment       $fulfillment The fulfillment being transitioned.
	 * @param TransitionContext $context     Guard-relevant data for this attempt.
	 */
	public function is_satisfied( Fulfillment $fulfillment, TransitionContext $context ): bool {
		unset( $fulfillment );

		return $context->tracking_requirement_satisfied();
	}

	/**
	 * Human-readable reason to show when this guard blocks a transition.
	 */
	public function unmet_reason(): string {
		return 'A tracking number must be recorded before marking as shipped.';
	}
}
