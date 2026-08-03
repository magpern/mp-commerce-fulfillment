<?php
/**
 * Presentation guidance for the Packing Workspace stage banner.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

use MPCF\Application\AvailableTransition;
use MPCF\Domain\Workflow\WorkflowDefinition;

/**
 * Maps workflow state keys to operator-facing stage copy and softens
 * known guard rejection messages. Does not evaluate transition eligibility
 * — callers pass the primary {@see AvailableTransition} from
 * {@see \MPCF\Application\WorkflowService::available_transitions()}.
 */
final class WorkspaceStageGuidance {

	/**
	 * Operator-facing stage guidance for one fulfillment state.
	 *
	 * @param string                   $state      Current fulfillment state key.
	 * @param AvailableTransition|null $primary    Primary forward candidate, if any.
	 * @param WorkflowDefinition       $definition Governing workflow (for labels).
	 * @return array{state_key: string, state_label: string, title: string, instruction: string, next_action_label: string, shipment_emphasis: string}
	 */
	public static function for_state( string $state, ?AvailableTransition $primary, WorkflowDefinition $definition ): array {
		$state_label = $definition->has_state( $state )
			? $definition->state( $state )->label()
			: $state;

		$known = self::known_guidance();

		if ( isset( $known[ $state ] ) ) {
			$guidance                      = $known[ $state ];
			$guidance['state_key']         = $state;
			$guidance['state_label']       = $state_label;
			$guidance['next_action_label'] = null !== $primary
				? $primary->label()
				: (string) $guidance['next_action_label'];

			return $guidance;
		}

		$next = null !== $primary ? $primary->label() : __( 'Continue', 'mp-commerce-fulfillment' );

		return array(
			'state_key'         => $state,
			'state_label'       => $state_label,
			'title'             => $state_label,
			'instruction'       => __( 'Review this fulfillment and use the primary action when ready.', 'mp-commerce-fulfillment' ),
			'next_action_label' => $next,
			'shipment_emphasis' => 'secondary',
		);
	}

	/**
	 * Softens known guard rejection codes into operator language. Unknown
	 * codes keep the engine message (never the bare code).
	 *
	 * @param string|null $rejection_code    Machine-readable guard id.
	 * @param string|null $rejection_message Engine message fallback.
	 */
	public static function operator_guard_message( ?string $rejection_code, ?string $rejection_message ): string {
		$map = array(
			'all_items_picked' => __( 'Pick all ordered items before marking this fulfillment as picked.', 'mp-commerce-fulfillment' ),
			'all_items_packed' => __( 'Pack all picked items before marking this fulfillment as packed.', 'mp-commerce-fulfillment' ),
			'has_shipment'     => __( 'Add a shipment before shipping this fulfillment.', 'mp-commerce-fulfillment' ),
			'has_tracking'     => __( 'Enter a tracking number before shipping.', 'mp-commerce-fulfillment' ),
		);

		if ( null !== $rejection_code && isset( $map[ $rejection_code ] ) ) {
			return $map[ $rejection_code ];
		}

		$message = trim( (string) $rejection_message );

		return '' !== $message ? $message : __( 'This action is not available yet.', 'mp-commerce-fulfillment' );
	}

	/**
	 * Whether shipment/package controls should render expanded for a state.
	 *
	 * @param string $state Current fulfillment state key.
	 */
	public static function shipment_section_open( string $state ): bool {
		return in_array( $state, array( 'picked', 'packing', 'packed', 'shipped', 'delivered', 'completed' ), true );
	}

	/**
	 * CSS emphasis token for the shipment panel: `muted`, `secondary`, or `primary`.
	 *
	 * @param string $state Current fulfillment state key.
	 */
	public static function shipment_emphasis( string $state ): string {
		if ( in_array( $state, array( 'packed', 'shipped' ), true ) ) {
			return 'primary';
		}

		if ( in_array( $state, array( 'picked', 'packing' ), true ) ) {
			return 'secondary';
		}

		return 'muted';
	}

	/**
	 * Built-in guidance keyed by standard-workflow state.
	 *
	 * @return array<string, array{title: string, instruction: string, next_action_label: string, shipment_emphasis: string}>
	 */
	private static function known_guidance(): array {
		return array(
			'queued'      => array(
				'title'             => __( 'Queued', 'mp-commerce-fulfillment' ),
				'instruction'       => __( 'Start picking to record the items you collect.', 'mp-commerce-fulfillment' ),
				'next_action_label' => __( 'Start picking', 'mp-commerce-fulfillment' ),
				'shipment_emphasis' => 'muted',
			),
			'picking'     => array(
				'title'             => __( 'Picking', 'mp-commerce-fulfillment' ),
				'instruction'       => __( 'Record each item as it is picked.', 'mp-commerce-fulfillment' ),
				'next_action_label' => __( 'Mark picked', 'mp-commerce-fulfillment' ),
				'shipment_emphasis' => 'muted',
			),
			'picked'      => array(
				'title'             => __( 'Picked', 'mp-commerce-fulfillment' ),
				'instruction'       => __( 'Start packing the picked items.', 'mp-commerce-fulfillment' ),
				'next_action_label' => __( 'Start packing', 'mp-commerce-fulfillment' ),
				'shipment_emphasis' => 'secondary',
			),
			'packing'     => array(
				'title'             => __( 'Packing', 'mp-commerce-fulfillment' ),
				'instruction'       => __( 'Pack every picked item and complete the shipment details.', 'mp-commerce-fulfillment' ),
				'next_action_label' => __( 'Mark packed', 'mp-commerce-fulfillment' ),
				'shipment_emphasis' => 'secondary',
			),
			'packed'      => array(
				'title'             => __( 'Packed', 'mp-commerce-fulfillment' ),
				'instruction'       => __( 'Confirm shipment and tracking details, then ship the order.', 'mp-commerce-fulfillment' ),
				'next_action_label' => __( 'Ship order', 'mp-commerce-fulfillment' ),
				'shipment_emphasis' => 'primary',
			),
			'shipped'     => array(
				'title'             => __( 'Shipped', 'mp-commerce-fulfillment' ),
				'instruction'       => __( 'This fulfillment has been shipped.', 'mp-commerce-fulfillment' ),
				'next_action_label' => __( 'Next order', 'mp-commerce-fulfillment' ),
				'shipment_emphasis' => 'primary',
			),
			'delivered'   => array(
				'title'             => __( 'Delivered', 'mp-commerce-fulfillment' ),
				'instruction'       => __( 'This fulfillment has been delivered.', 'mp-commerce-fulfillment' ),
				'next_action_label' => __( 'Next order', 'mp-commerce-fulfillment' ),
				'shipment_emphasis' => 'secondary',
			),
			'completed'   => array(
				'title'             => __( 'Completed', 'mp-commerce-fulfillment' ),
				'instruction'       => __( 'This fulfillment is complete. No further warehouse action is required.', 'mp-commerce-fulfillment' ),
				'next_action_label' => __( 'Next order', 'mp-commerce-fulfillment' ),
				'shipment_emphasis' => 'secondary',
			),
			'problem'     => array(
				'title'             => __( 'Problem', 'mp-commerce-fulfillment' ),
				'instruction'       => __( 'Resolve the problem, then return this fulfillment to the workflow.', 'mp-commerce-fulfillment' ),
				'next_action_label' => __( 'Continue', 'mp-commerce-fulfillment' ),
				'shipment_emphasis' => 'secondary',
			),
			'waiting'     => array(
				'title'             => __( 'Waiting', 'mp-commerce-fulfillment' ),
				'instruction'       => __( 'This fulfillment is waiting. Resume when the blocker is cleared.', 'mp-commerce-fulfillment' ),
				'next_action_label' => __( 'Continue', 'mp-commerce-fulfillment' ),
				'shipment_emphasis' => 'muted',
			),
			'backordered' => array(
				'title'             => __( 'Backordered', 'mp-commerce-fulfillment' ),
				'instruction'       => __( 'Items are backordered. Resume when stock is available.', 'mp-commerce-fulfillment' ),
				'next_action_label' => __( 'Continue', 'mp-commerce-fulfillment' ),
				'shipment_emphasis' => 'muted',
			),
			'cancelled'   => array(
				'title'             => __( 'Cancelled', 'mp-commerce-fulfillment' ),
				'instruction'       => __( 'This fulfillment was cancelled. No further warehouse action is required.', 'mp-commerce-fulfillment' ),
				'next_action_label' => __( 'Next order', 'mp-commerce-fulfillment' ),
				'shipment_emphasis' => 'muted',
			),
		);
	}
}
