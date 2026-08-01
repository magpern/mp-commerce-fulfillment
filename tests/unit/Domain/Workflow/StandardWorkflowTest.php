<?php
/**
 * Tests for the standard workflow definition against Architecture Plan §6.2.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Workflow;

use MPCF\Capabilities;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Domain\Workflow\State;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the standard workflow definition against Architecture Plan §6.2.
 */
final class StandardWorkflowTest extends TestCase {

	public function test_definition_builds_and_passes_structural_validation(): void {
		$definition = StandardWorkflow::definition();

		self::assertSame( 'standard', $definition->name() );
		self::assertSame( 'queued', $definition->initial_state() );
	}

	public function test_declares_exactly_the_twelve_documented_states(): void {
		$keys = array_map( static fn( State $state ): string => $state->key(), StandardWorkflow::definition()->states() );

		sort( $keys );

		self::assertSame(
			array( 'backordered', 'cancelled', 'completed', 'delivered', 'packed', 'packing', 'picked', 'picking', 'problem', 'queued', 'shipped', 'waiting' ),
			$keys
		);
	}

	/**
	 * @dataProvider forward_path_edges
	 */
	public function test_forward_path_edge_exists( string $from, string $to ): void {
		self::assertNotNull( StandardWorkflow::definition()->transition( $from, $to ), "Expected {$from} -> {$to} to exist." );
	}

	/**
	 * @return list<array{0:string,1:string}>
	 */
	public static function forward_path_edges(): array {
		return array(
			array( 'queued', 'picking' ),
			array( 'picking', 'picked' ),
			array( 'picked', 'packing' ),
			array( 'packing', 'packed' ),
			array( 'packed', 'shipped' ),
			array( 'shipped', 'delivered' ),
			array( 'delivered', 'completed' ),
			array( 'shipped', 'completed' ),
			// Documented shortcuts (§6.2).
			array( 'queued', 'packing' ),
			array( 'packed', 'completed' ),
		);
	}

	public function test_picking_to_picked_guards_all_items_picked(): void {
		$transition = StandardWorkflow::definition()->transition( 'picking', 'picked' );

		self::assertContains( 'all_items_picked', $transition->guards() );
		self::assertFalse( $transition->requires_reason() );
	}

	public function test_packing_to_packed_guards_the_documented_three_conditions(): void {
		$transition = StandardWorkflow::definition()->transition( 'packing', 'packed' );

		self::assertSame( array( 'all_items_packed', 'package_spec_present', 'photo_required' ), $transition->guards() );
	}

	public function test_packed_to_shipped_requires_manage_shipments_and_has_shipment_guard(): void {
		$transition = StandardWorkflow::definition()->transition( 'packed', 'shipped' );

		self::assertSame( Capabilities::MANAGE_SHIPMENTS, $transition->required_capability() );
		self::assertContains( 'has_shipment', $transition->guards() );
	}

	public function test_every_working_state_can_enter_every_exception_state_with_a_required_reason(): void {
		$definition = StandardWorkflow::definition();

		foreach ( array( 'picking', 'picked', 'packing', 'packed', 'shipped', 'delivered' ) as $from ) {
			foreach ( array( 'problem', 'waiting', 'backordered' ) as $to ) {
				$transition = $definition->transition( $from, $to );

				self::assertNotNull( $transition, "Expected {$from} -> {$to} to exist." );
				self::assertTrue( $transition->requires_reason(), "Expected {$from} -> {$to} to require a reason." );
				self::assertSame( Capabilities::PROCESS_FULFILLMENTS, $transition->required_capability() );
			}
		}
	}

	public function test_queued_cannot_directly_enter_an_exception_state(): void {
		$definition = StandardWorkflow::definition();

		foreach ( array( 'problem', 'waiting', 'backordered' ) as $to ) {
			self::assertNull( $definition->transition( 'queued', $to ) );
		}
	}

	/**
	 * @dataProvider cancellable_states
	 */
	public function test_cancellation_is_reachable_and_reason_required_from_every_non_terminal_state( string $from ): void {
		$transition = StandardWorkflow::definition()->transition( $from, 'cancelled' );

		self::assertNotNull( $transition, "Expected {$from} -> cancelled to exist." );
		self::assertTrue( $transition->requires_reason() );
		self::assertSame( Capabilities::CANCEL_FULFILLMENT, $transition->required_capability() );
	}

	/**
	 * @return list<array{0:string}>
	 */
	public static function cancellable_states(): array {
		return array(
			array( 'queued' ),
			array( 'picking' ),
			array( 'picked' ),
			array( 'packing' ),
			array( 'packed' ),
			array( 'shipped' ),
			array( 'delivered' ),
			array( 'problem' ),
			array( 'waiting' ),
			array( 'backordered' ),
		);
	}

	public function test_terminal_states_cannot_be_cancelled(): void {
		$definition = StandardWorkflow::definition();

		self::assertNull( $definition->transition( 'completed', 'cancelled' ) );
		self::assertNull( $definition->transition( 'cancelled', 'cancelled' ) );
	}

	public function test_completed_and_cancelled_are_the_only_terminal_states(): void {
		$terminal = array_filter( StandardWorkflow::definition()->states(), static fn( State $state ): bool => $state->is_terminal() );
		$keys     = array_map( static fn( State $state ): string => $state->key(), $terminal );

		sort( $keys );

		self::assertSame( array( 'cancelled', 'completed' ), $keys );
	}

	public function test_picking_and_packing_expect_an_operator(): void {
		$definition = StandardWorkflow::definition();

		self::assertTrue( $definition->state( 'picking' )->expects_operator() );
		self::assertTrue( $definition->state( 'packing' )->expects_operator() );
		self::assertFalse( $definition->state( 'queued' )->expects_operator() );
	}

	public function test_only_terminal_states_are_excluded_from_counts_as_open(): void {
		$definition = StandardWorkflow::definition();

		foreach ( $definition->states() as $state ) {
			if ( $state->is_terminal() ) {
				self::assertFalse( $state->counts_as_open(), "{$state->key()} is terminal and must not count as open." );
			} else {
				self::assertTrue( $state->counts_as_open(), "{$state->key()} is non-terminal and must count as open." );
			}
		}
	}
}
