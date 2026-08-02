<?php
/**
 * Guard: the photo-required setting, if any, is satisfied.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\Guard;

use MPCF\Domain\Fulfillment;
use MPCF\Engine\TransitionContext;
use MPCF\Engine\TransitionGuard;

/**
 * Blocks `packing -> packed` when the photo-required setting is on and no
 * photo has been captured. Photo capture is a Milestone 5 feature; this
 * guard's condition is pre-resolved by the caller into
 * `TransitionContext::photo_requirement_satisfied()` — the Engine layer
 * never reads a setting itself — so it is already effectively a no-op
 * until Milestone 5 ships a capture UI and turns the setting on.
 */
final class PhotoRequiredGuard implements TransitionGuard {

	/**
	 * The guard identifier this class implements.
	 */
	public function id(): string {
		return 'photo_required';
	}

	/**
	 * Whether this guard's condition is currently met.
	 *
	 * @param Fulfillment       $fulfillment The fulfillment being transitioned.
	 * @param TransitionContext $context     Guard-relevant data for this attempt.
	 */
	public function is_satisfied( Fulfillment $fulfillment, TransitionContext $context ): bool {
		unset( $fulfillment );

		return $context->photo_requirement_satisfied();
	}

	/**
	 * Human-readable reason to show when this guard blocks a transition.
	 */
	public function unmet_reason(): string {
		return 'A package photo is required before packing is complete.';
	}
}
