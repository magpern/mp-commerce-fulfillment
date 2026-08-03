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
use MPCF\Settings;

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
 * model exists until Milestone 5. `tracking_requirement_satisfied` now
 * reflects the `require_tracking_before_ship` setting (F21) — satisfied
 * whenever that setting is off, or whenever any of the fulfillment's
 * shipments has a recorded tracking number when it is on. Injecting
 * {@see Settings} here does not reintroduce a platform-integration
 * dependency into this layer (invariant I6): `Settings` alone owns the
 * underlying options read/write, and this file never touches that
 * directly — it only reads one already-sanitized boolean off it, the
 * same way it reads booleans off repository results.
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
	 * Plugin settings, for `require_tracking_before_ship`.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Builds the factory.
	 *
	 * @param FulfillmentItemRepository $items     Line item persistence.
	 * @param ShipmentRepository        $shipments Shipment persistence.
	 * @param PackageRepository         $packages  Package persistence.
	 * @param Settings                  $settings  Plugin settings, for `require_tracking_before_ship`.
	 */
	public function __construct( FulfillmentItemRepository $items, ShipmentRepository $shipments, PackageRepository $packages, Settings $settings ) {
		$this->items     = $items;
		$this->shipments = $shipments;
		$this->packages  = $packages;
		$this->settings  = $settings;
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
			! $this->settings->require_tracking_before_ship() || array() === $shipments || $this->any_shipment_has_tracking( $shipments )
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

	/**
	 * Whether any of a fulfillment's shipments has a recorded tracking
	 * number — the real data source for `tracking_requirement_satisfied`
	 * when `require_tracking_before_ship` is on.
	 *
	 * @param array<int, Shipment> $shipments Shipments to check.
	 */
	private function any_shipment_has_tracking( array $shipments ): bool {
		foreach ( $shipments as $shipment ) {
			if ( $shipment->has_tracking() ) {
				return true;
			}
		}

		return false;
	}
}
