<?php
/**
 * The default forward workflow every fulfillment uses unless a custom
 * workflow is registered.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Workflow;

use MPCF\Capabilities;

/**
 * Builds the standard workflow's {@see WorkflowDefinition}, per Architecture
 * Plan §6.2. The forward path:
 *
 *     Queued -> picking -> picked -> packing -> packed -> shipped -> delivered -> completed
 *
 * plus two documented shortcut edges (`queued -> packing` for stores that
 * skip a discrete picking phase, `packed -> completed` for pickup orders),
 * a `shipped -> completed` edge (completion can follow shipment directly,
 * before a delivery confirmation exists), an exception band
 * (`problem`/`waiting`/`backordered`, entered from any *working* state,
 * always with a required, audited reason), and `cancelled` (reachable from
 * any non-terminal state, also reason-required).
 *
 * The exception→origin "resolve" edge is deliberately not represented here
 * — see {@see WorkflowDefinition}'s docblock.
 *
 * Referencing `Capabilities`' string constants (not the literal strings)
 * keeps every permission check flowing through that single source of
 * truth, exactly as the capability class's own contract requires; reading
 * a class constant does not execute any of that class's methods, so this
 * file still needs nothing beyond plain PHP to be unit-tested.
 */
final class StandardWorkflow {

	public const NAME = 'standard';

	/**
	 * Working states an operator actively moves a fulfillment through
	 * before it either completes or is cancelled.
	 */
	private const WORKING_STATES = array( 'picking', 'picked', 'packing', 'packed', 'shipped', 'delivered' );

	/**
	 * Every non-terminal state — {@see self::WORKING_STATES} plus `queued`
	 * and the three exception states — can be cancelled.
	 */
	private const CANCELLABLE_STATES = array( 'queued', 'picking', 'picked', 'packing', 'packed', 'shipped', 'delivered', 'problem', 'waiting', 'backordered' );

	/**
	 * Exception states any working state may enter.
	 */
	private const EXCEPTION_STATES = array( 'problem', 'waiting', 'backordered' );

	/**
	 * The standard workflow, as a validated {@see WorkflowDefinition}.
	 */
	public static function definition(): WorkflowDefinition {
		return WorkflowDefinition::from_array( self::data() );
	}

	/**
	 * The standard workflow's array shape, before validation.
	 *
	 * @return array{name:string,version:int,initial_state:string,states:list<array<string,mixed>>,transitions:list<array<string,mixed>>}
	 */
	public static function data(): array {
		return array(
			'name'          => self::NAME,
			'version'       => 1,
			'initial_state' => 'queued',
			'states'        => self::states(),
			'transitions'   => self::transitions(),
		);
	}

	/**
	 * The standard workflow's states, in declaration order.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function states(): array {
		return array(
			array(
				'key'            => 'queued',
				'label'          => 'Queued',
				'badge_variant'  => 'recommended',
				'type'           => State::TYPE_INITIAL,
				'counts_as_open' => true,
			),
			array(
				'key'              => 'picking',
				'label'            => 'Picking',
				'badge_variant'    => 'warning',
				'type'             => State::TYPE_WORKING,
				'counts_as_open'   => true,
				'expects_operator' => true,
			),
			array(
				'key'            => 'picked',
				'label'          => 'Picked',
				'badge_variant'  => 'available',
				'type'           => State::TYPE_WORKING,
				'counts_as_open' => true,
			),
			array(
				'key'              => 'packing',
				'label'            => 'Packing',
				'badge_variant'    => 'warning',
				'type'             => State::TYPE_WORKING,
				'counts_as_open'   => true,
				'expects_operator' => true,
			),
			array(
				'key'            => 'packed',
				'label'          => 'Packed',
				'badge_variant'  => 'available',
				'type'           => State::TYPE_WORKING,
				'counts_as_open' => true,
			),
			array(
				'key'            => 'shipped',
				'label'          => 'Shipped',
				'badge_variant'  => 'active',
				'type'           => State::TYPE_WORKING,
				'counts_as_open' => true,
			),
			array(
				'key'            => 'delivered',
				'label'          => 'Delivered',
				'badge_variant'  => 'active',
				'type'           => State::TYPE_WORKING,
				'counts_as_open' => true,
			),
			array(
				'key'            => 'completed',
				'label'          => 'Completed',
				'badge_variant'  => 'active',
				'type'           => State::TYPE_TERMINAL,
				'counts_as_open' => false,
			),
			array(
				'key'            => 'problem',
				'label'          => 'Problem',
				'badge_variant'  => 'error',
				'type'           => State::TYPE_EXCEPTION,
				'counts_as_open' => true,
			),
			array(
				'key'            => 'waiting',
				'label'          => 'Waiting',
				'badge_variant'  => 'warning',
				'type'           => State::TYPE_EXCEPTION,
				'counts_as_open' => true,
			),
			array(
				'key'            => 'backordered',
				'label'          => 'Backordered',
				'badge_variant'  => 'missing',
				'type'           => State::TYPE_EXCEPTION,
				'counts_as_open' => true,
			),
			array(
				'key'            => 'cancelled',
				'label'          => 'Cancelled',
				'badge_variant'  => 'disabled',
				'type'           => State::TYPE_TERMINAL,
				'counts_as_open' => false,
			),
		);
	}

	/**
	 * The standard workflow's transitions: the forward path and its two
	 * shortcuts, every working-state-to-exception-state edge, and every
	 * non-terminal-state-to-cancelled edge.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function transitions(): array {
		$transitions = array(
			self::forward_edge( 'queued', 'picking' ),
			self::forward_edge( 'picking', 'picked', array( 'all_items_picked' ) ),
			self::forward_edge( 'picked', 'packing' ),
			self::forward_edge( 'packing', 'packed', array( 'all_items_packed', 'package_spec_present', 'photo_required' ) ),
			self::forward_edge( 'packed', 'shipped', array( 'has_shipment' ), Capabilities::MANAGE_SHIPMENTS ),
			self::forward_edge( 'shipped', 'delivered' ),
			self::forward_edge( 'delivered', 'completed' ),
			self::forward_edge( 'shipped', 'completed' ),
			// Shortcuts (Architecture Plan §6.2): stores that skip a
			// discrete picking phase, and pickup orders that never ship.
			self::forward_edge( 'queued', 'packing' ),
			self::forward_edge( 'packed', 'completed' ),
		);

		foreach ( self::WORKING_STATES as $from ) {
			foreach ( self::EXCEPTION_STATES as $to ) {
				$transitions[] = array(
					'from'                => $from,
					'to'                  => $to,
					'required_capability' => Capabilities::PROCESS_FULFILLMENTS,
					'requires_reason'     => true,
					'guards'              => array(),
					'events'              => array( 'fulfillment.state_changed' ),
				);
			}
		}

		foreach ( self::CANCELLABLE_STATES as $from ) {
			$transitions[] = array(
				'from'                => $from,
				'to'                  => 'cancelled',
				'required_capability' => Capabilities::CANCEL_FULFILLMENT,
				'requires_reason'     => true,
				'guards'              => array(),
				'events'              => array( 'fulfillment.state_changed' ),
			);
		}

		return $transitions;
	}

	/**
	 * Builds one forward-path transition array (never reason-required —
	 * only exception-entry and cancellation require a reason).
	 *
	 * @param string             $from       Origin state key.
	 * @param string             $to         Destination state key.
	 * @param array<int, string> $guards     Ordered guard identifiers.
	 * @param string             $capability Capability required to take this edge.
	 */
	private static function forward_edge( string $from, string $to, array $guards = array(), string $capability = Capabilities::PROCESS_FULFILLMENTS ): array {
		return array(
			'from'                => $from,
			'to'                  => $to,
			'required_capability' => $capability,
			'requires_reason'     => false,
			'guards'              => $guards,
			'events'              => array( 'fulfillment.state_changed' ),
		);
	}
}
