<?php
/**
 * Bundled workspace context flags.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Woo;

use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Domain\Workflow\WorkflowDefinition;
use WC_Order;

/**
 * Architecture Plan §9.4/§IV.5.2: `mpcf_workspace_flags` is the named
 * extension slot the Packing Workspace's context column renders from —
 * `Admin\WorkspacePage` only ever calls `apply_filters()` on it and knows
 * nothing about WooCommerce itself (invariant I8 confines that here).
 * This class supplies the three flags Milestone 2 bundles; a merchant or
 * another plugin can add more via the same filter without touching this
 * class.
 *
 * `HIGH_VALUE_THRESHOLD` is a fixed default, not a new settings key or
 * filter of its own — Milestone 2's frozen public-surface list (§IV.9)
 * names `mpcf_workspace_flags` only, not a threshold-configuration hook;
 * making the threshold itself configurable is a natural Milestone 4
 * addition (alongside the notification policy it would pair with), not
 * this milestone's to introduce unreviewed.
 */
final class WorkspaceFlags {

	/**
	 * Order total (in the store's own currency, no conversion) at or
	 * above which an order is flagged "high value".
	 */
	private const HIGH_VALUE_THRESHOLD = 200.0;

	/**
	 * Fulfillment lookup, for the repeat-problem-customer flag's
	 * cross-order check.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

	/**
	 * The governing workflow, to recognize an exception state without
	 * hardcoding a duplicate list of state keys.
	 *
	 * @var WorkflowDefinition
	 */
	private WorkflowDefinition $definition;

	/**
	 * Builds the flag provider.
	 *
	 * @param FulfillmentRepository $fulfillments Fulfillment lookup.
	 * @param WorkflowDefinition    $definition   The governing workflow.
	 */
	public function __construct( FulfillmentRepository $fulfillments, WorkflowDefinition $definition ) {
		$this->fulfillments = $fulfillments;
		$this->definition   = $definition;
	}

	/**
	 * Registers this class's filter callback.
	 */
	public function register(): void {
		add_filter( 'mpcf_workspace_flags', array( $this, 'add_bundled_flags' ), 10, 2 );
	}

	/**
	 * Appends every bundled flag that applies to one fulfillment.
	 *
	 * @param array<int, array{icon: string, label: string}> $flags          Flags already collected by earlier filter callbacks.
	 * @param int                                            $fulfillment_id Fulfillment being flagged.
	 * @return array<int, array{icon: string, label: string}>
	 */
	public function add_bundled_flags( array $flags, int $fulfillment_id ): array {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return $flags;
		}

		$order = wc_get_order( $fulfillment->order_id() );

		if ( ! $order instanceof WC_Order ) {
			return $flags;
		}

		if ( '' !== trim( (string) $order->get_customer_note() ) ) {
			$flags[] = array(
				'icon'  => 'dashicons-format-chat',
				'label' => __( 'Customer note', 'mp-commerce-fulfillment' ),
			);
		}

		if ( (float) $order->get_total() >= self::HIGH_VALUE_THRESHOLD ) {
			$flags[] = array(
				'icon'  => 'dashicons-star-filled',
				'label' => __( 'High value', 'mp-commerce-fulfillment' ),
			);
		}

		if ( $this->is_repeat_problem_customer( $order ) ) {
			$flags[] = array(
				'icon'  => 'dashicons-warning',
				'label' => __( 'Repeat problem customer', 'mp-commerce-fulfillment' ),
			);
		}

		return $flags;
	}

	/**
	 * Whether this order's customer has another order whose fulfillment is
	 * currently sitting in an exception state. Deliberately a simple,
	 * current-state check, not a full audit-history scan for a problem
	 * that was later resolved — a merchant reviewing this flag cares about
	 * an open issue right now, not one already closed.
	 *
	 * @param WC_Order $order The order being flagged.
	 */
	private function is_repeat_problem_customer( WC_Order $order ): bool {
		$customer_id = $order->get_customer_id();

		if ( 0 === $customer_id ) {
			// A guest checkout has no reliable cross-order identity to
			// match on — billing name/email are not a safe substitute
			// (shared households, typos, deliberate reuse).
			return false;
		}

		$order_ids = wc_get_orders(
			array(
				'customer' => $customer_id,
				'limit'    => -1,
				'return'   => 'ids',
			)
		);

		if ( ! is_array( $order_ids ) || empty( $order_ids ) ) {
			return false;
		}

		foreach ( $order_ids as $sibling_order_id ) {
			if ( $sibling_order_id === $order->get_id() ) {
				continue;
			}

			$sibling = $this->fulfillments->find_by_order_id( (int) $sibling_order_id );

			if ( null !== $sibling && $this->definition->has_state( $sibling->state() ) && $this->definition->state( $sibling->state() )->is_exception() ) {
				return true;
			}
		}

		return false;
	}
}
