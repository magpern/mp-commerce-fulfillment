<?php
/**
 * Guard: a shipment has been confirmed.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\Guard;

use MPCF\Domain\Fulfillment;
use MPCF\Engine\TransitionContext;
use MPCF\Engine\TransitionGuard;

/**
 * Blocks `packed -> shipped` until the caller confirms a shipment exists.
 * Milestone 1 has no dedicated shipment data model yet (that lands in
 * Milestone 2's `shipments` table) — until then, the Application layer
 * supplies this from manual operator confirmation.
 */
final class HasShipmentGuard implements TransitionGuard {

	/**
	 * The guard identifier this class implements.
	 */
	public function id(): string {
		return 'has_shipment';
	}

	/**
	 * Whether this guard's condition is currently met.
	 *
	 * @param Fulfillment       $fulfillment The fulfillment being transitioned.
	 * @param TransitionContext $context     Guard-relevant data for this attempt.
	 */
	public function is_satisfied( Fulfillment $fulfillment, TransitionContext $context ): bool {
		unset( $fulfillment );

		return $context->has_shipment();
	}

	/**
	 * Human-readable reason to show when this guard blocks a transition.
	 */
	public function unmet_reason(): string {
		return 'A shipment must be recorded before marking as shipped.';
	}
}
