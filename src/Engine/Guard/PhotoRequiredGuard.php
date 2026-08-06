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
 * Blocks `packing -> packed` when `photos_required` is on and no active
 * kind=package photo has been captured. The condition is pre-resolved by
 * {@see \MPCF\Application\TransitionContextFactory} into
 * `TransitionContext::photo_requirement_satisfied()` — the Engine layer
 * never reads a setting or PhotoService itself (M6; purity).
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
		return 'A sealed-package photo is required before this fulfillment can be marked packed.';
	}
}
