<?php
/**
 * Guard: every line item fully packed.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\Guard;

use MPCF\Domain\Fulfillment;
use MPCF\Engine\TransitionContext;
use MPCF\Engine\TransitionGuard;

/**
 * Blocks `packing -> packed` until every line item's packed quantity meets
 * its ordered quantity.
 */
final class AllItemsPackedGuard implements TransitionGuard {

	/**
	 * The guard identifier this class implements.
	 */
	public function id(): string {
		return 'all_items_packed';
	}

	/**
	 * Whether this guard's condition is currently met.
	 *
	 * @param Fulfillment       $fulfillment The fulfillment being transitioned.
	 * @param TransitionContext $context     Guard-relevant data for this attempt.
	 */
	public function is_satisfied( Fulfillment $fulfillment, TransitionContext $context ): bool {
		unset( $fulfillment );

		foreach ( $context->items() as $item ) {
			if ( ! $item->is_fully_packed() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Human-readable reason to show when this guard blocks a transition.
	 */
	public function unmet_reason(): string {
		return 'Every line item must be fully packed first.';
	}
}
