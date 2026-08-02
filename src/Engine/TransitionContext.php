<?php
/**
 * Guard-relevant data for one transition attempt.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine;

use MPCF\Domain\FulfillmentItem;

/**
 * Everything a {@see \MPCF\Engine\TransitionGuard} might need to evaluate
 * eligibility, assembled by the caller (Application layer) before invoking
 * {@see WorkflowEngine::transition()}.
 *
 * Deliberately just data: this class has no behavior beyond returning what
 * it was constructed with, so the Engine layer never has to reach outside
 * itself (into a repository, a setting, or a platform function) to answer
 * a guard question — the caller resolves all of that first.
 *
 * From Milestone 2 on, `package_spec_present` and `has_shipment` are
 * derived from real `mpcf_shipments`/`mpcf_packages` data by
 * {@see \MPCF\Application\TransitionContextFactory} (Architecture Plan
 * §IV.3.B, findings B/C/D) — Milestone 1 had no shipment/package data model
 * yet, so they were caller-asserted booleans then. `photo_requirement_satisfied`
 * and `tracking_requirement_satisfied` are pre-resolved by the caller
 * against a setting and whatever real state exists, so this class never
 * needs to read a setting or a repository itself.
 */
final class TransitionContext {

	/**
	 * Line items belonging to the fulfillment being transitioned.
	 *
	 * @var list<FulfillmentItem>
	 */
	private array $items;

	/**
	 * Whether a package spec (weight/dimensions) has been confirmed.
	 *
	 * @var bool
	 */
	private bool $package_spec_present;

	/**
	 * Whether a shipment has been confirmed.
	 *
	 * @var bool
	 */
	private bool $has_shipment;

	/**
	 * Whether the photo-required setting, if any, has been satisfied.
	 *
	 * @var bool
	 */
	private bool $photo_requirement_satisfied;

	/**
	 * Whether the tracking-required setting, if any, has been satisfied.
	 *
	 * @var bool
	 */
	private bool $tracking_requirement_satisfied;

	/**
	 * Builds a transition context.
	 *
	 * @param array<int, FulfillmentItem> $items                           Line items belonging to the fulfillment.
	 * @param bool                        $package_spec_present            Whether a package spec has been confirmed.
	 * @param bool                        $has_shipment                    Whether a shipment has been confirmed.
	 * @param bool                        $photo_requirement_satisfied     Whether the photo-required setting is satisfied.
	 * @param bool                        $tracking_requirement_satisfied  Whether the tracking-required setting is satisfied.
	 */
	public function __construct(
		array $items = array(),
		bool $package_spec_present = false,
		bool $has_shipment = false,
		bool $photo_requirement_satisfied = true,
		bool $tracking_requirement_satisfied = true
	) {
		$this->items                          = $items;
		$this->package_spec_present           = $package_spec_present;
		$this->has_shipment                   = $has_shipment;
		$this->photo_requirement_satisfied    = $photo_requirement_satisfied;
		$this->tracking_requirement_satisfied = $tracking_requirement_satisfied;
	}

	/**
	 * Line items belonging to the fulfillment being transitioned.
	 *
	 * @return list<FulfillmentItem>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Whether a package spec (weight/dimensions) has been confirmed.
	 */
	public function package_spec_present(): bool {
		return $this->package_spec_present;
	}

	/**
	 * Whether a shipment has been confirmed.
	 */
	public function has_shipment(): bool {
		return $this->has_shipment;
	}

	/**
	 * Whether the photo-required setting, if any, has been satisfied.
	 */
	public function photo_requirement_satisfied(): bool {
		return $this->photo_requirement_satisfied;
	}

	/**
	 * Whether the tracking-required setting, if any, has been satisfied.
	 */
	public function tracking_requirement_satisfied(): bool {
		return $this->tracking_requirement_satisfied;
	}
}
