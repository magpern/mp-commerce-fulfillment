<?php
/**
 * Builds a real-data TransitionContext for one fulfillment.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use MPCF\Domain\Repository\FulfillmentItemRepository;
use MPCF\Domain\Repository\PackageRepository;
use MPCF\Domain\Repository\ShipmentRepository;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Engine\TransitionContext;

/**
 * Architecture Plan §IV.3.B, resolving findings B/C/D: `package_spec_present`
 * and `has_shipment` used to be booleans the caller asserted (`true, true`
 * from {@see \MPCF\Admin\FulfillmentDetailPage}, `false, false` from
 * {@see \MPCF\Admin\QueuePage}) because Milestone 1 had no shipment/package
 * data model to ask instead. It exists now, so this class is the one place
 * that asks it — {@see \MPCF\Application\WorkflowService} calls this and
 * nowhere else builds a {@see TransitionContext} from real data.
 *
 * `photo_requirement_satisfied` stays hardcoded `true`: no photo capture
 * model exists until Milestone 5. `tracking_requirement_satisfied` stays
 * hardcoded `true` too, for the same reason `photo_requirement_satisfied`
 * did through Milestone 1-4 — the `require_tracking_before_ship` setting
 * this flag will reflect does not exist until this milestone's Phase F
 * (F21); until then {@see \MPCF\Engine\Guard\HasTrackingGuard} is a
 * standing no-op, wired in ahead of the setting it depends on rather than
 * added alongside it, on the same precedent {@see \MPCF\Engine\Guard\PhotoRequiredGuard}
 * already set.
 */
final class TransitionContextFactory {

	/**
	 * Line item persistence.
	 *
	 * @var FulfillmentItemRepository
	 */
	private FulfillmentItemRepository $items;

	/**
	 * Shipment persistence.
	 *
	 * @var ShipmentRepository
	 */
	private ShipmentRepository $shipments;

	/**
	 * Package persistence.
	 *
	 * @var PackageRepository
	 */
	private PackageRepository $packages;

	/**
	 * Builds the factory.
	 *
	 * @param FulfillmentItemRepository $items     Line item persistence.
	 * @param ShipmentRepository        $shipments Shipment persistence.
	 * @param PackageRepository         $packages  Package persistence.
	 */
	public function __construct( FulfillmentItemRepository $items, ShipmentRepository $shipments, PackageRepository $packages ) {
		$this->items     = $items;
		$this->shipments = $shipments;
		$this->packages  = $packages;
	}

	/**
	 * Builds a transition context for one fulfillment from real data.
	 *
	 * @param int $fulfillment_id Fulfillment being transitioned.
	 */
	public function build( int $fulfillment_id ): TransitionContext {
		$shipments = $this->shipments->find_for_fulfillment( $fulfillment_id );

		return new TransitionContext(
			$this->items->find_for_fulfillment( $fulfillment_id ),
			$this->any_package_has_a_spec( $shipments ),
			array() !== $shipments,
			true,
			true
		);
	}

	/**
	 * Whether any package on any of a fulfillment's shipments has a
	 * recorded spec — the real data source for `package_spec_present`
	 * (Architecture Plan §IV.5.8 step 8: "at least one package has a
	 * weight").
	 *
	 * @param array<int, Shipment> $shipments Shipments to check packages on.
	 */
	private function any_package_has_a_spec( array $shipments ): bool {
		foreach ( $shipments as $shipment ) {
			foreach ( $this->packages->find_for_shipment( (int) $shipment->id() ) as $package ) {
				if ( $package->spec()->is_present() ) {
					return true;
				}
			}
		}

		return false;
	}
}
