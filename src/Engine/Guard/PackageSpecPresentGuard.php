<?php
/**
 * Guard: a package spec has been confirmed.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\Guard;

use MPCF\Domain\Fulfillment;
use MPCF\Engine\TransitionContext;
use MPCF\Engine\TransitionGuard;

/**
 * Blocks `packing -> packed` until the caller confirms a package spec
 * (weight/dimensions) is present. Milestone 1 has no dedicated package
 * data model yet (that lands in Milestone 2's `shipments`/`packages`
 * tables) — until then, the Application layer supplies this from manual
 * operator confirmation.
 */
final class PackageSpecPresentGuard implements TransitionGuard {

	/**
	 * The guard identifier this class implements.
	 */
	public function id(): string {
		return 'package_spec_present';
	}

	/**
	 * Whether this guard's condition is currently met.
	 *
	 * @param Fulfillment       $fulfillment The fulfillment being transitioned.
	 * @param TransitionContext $context     Guard-relevant data for this attempt.
	 */
	public function is_satisfied( Fulfillment $fulfillment, TransitionContext $context ): bool {
		unset( $fulfillment );

		return $context->package_spec_present();
	}

	/**
	 * Human-readable reason to show when this guard blocks a transition.
	 */
	public function unmet_reason(): string {
		return 'Package dimensions and weight must be confirmed before packing is complete.';
	}
}
