<?php
/**
 * Tests for the pure workflow decision engine.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Engine;

use DateTimeImmutable;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Engine\GuardRegistry;
use MPCF\Engine\TransitionContext;
use MPCF\Engine\WorkflowEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the pure workflow decision engine.
 */
final class WorkflowEngineTest extends TestCase {

	/**
	 * Engine under test, wired with every standard-workflow guard.
	 *
	 * @var WorkflowEngine
	 */
	private WorkflowEngine $engine;

	protected function setUp(): void {
		$this->engine = new WorkflowEngine( GuardRegistry::standard() );
	}

	private function fulfillment_in( string $state ): Fulfillment {
		$fulfillment = Fulfillment::intake( 1, 'woocommerce', 1, StandardWorkflow::NAME, 'queued', '#1', 'Jane', 1, new DateTimeImmutable() );

		if ( 'queued' !== $state ) {
			$fulfillment->apply_transition( $state, null, new DateTimeImmutable() );
		}

		return $fulfillment;
	}

	public function test_approves_a_guardless_forward_transition(): void {
		$result = $this->engine->transition(
			$this->fulfillment_in( 'queued' ),
			'picking',
			StandardWorkflow::definition(),
			new TransitionContext()
		);

		self::assertTrue( $result->is_approved() );
		self::assertSame( 'picking', $result->new_state() );
		self::assertNull( $result->entering_exception_from() );
		self::assertSame( array( 'fulfillment.state_changed' ), $result->events() );
	}

	public function test_rejects_a_guarded_transition_when_the_guard_is_not_satisfied(): void {
		$unpicked = FulfillmentItem::intake( 1, 1, 1, 0, 'SKU', 'Widget', 2 );

		$result = $this->engine->transition(
			$this->fulfillment_in( 'picking' ),
			'picked',
			StandardWorkflow::definition(),
			new TransitionContext( array( $unpicked ) )
		);

		self::assertFalse( $result->is_approved() );
		self::assertSame( 'all_items_picked', $result->rejection_code() );
		self::assertNotNull( $result->rejection_message() );
	}

	public function test_approves_a_guarded_transition_once_its_guard_is_satisfied(): void {
		$item = FulfillmentItem::intake( 1, 1, 1, 0, 'SKU', 'Widget', 2 );
		$item->record_picked( 2 );

		$result = $this->engine->transition(
			$this->fulfillment_in( 'picking' ),
			'picked',
			StandardWorkflow::definition(),
			new TransitionContext( array( $item ) )
		);

		self::assertTrue( $result->is_approved() );
		self::assertSame( 'picked', $result->new_state() );
	}

	public function test_stops_at_the_first_unsatisfied_guard_in_declared_order(): void {
		// packing -> packed guards, in order: all_items_packed,
		// package_spec_present, photo_required. An unpacked item makes the
		// *first* guard fail; package_spec_present would also fail (the
		// context supplies no flags) but must never be reached or reported.
		$unpacked = FulfillmentItem::intake( 1, 1, 1, 0, 'SKU', 'Widget', 2 );

		$result = $this->engine->transition(
			$this->fulfillment_in( 'packing' ),
			'packed',
			StandardWorkflow::definition(),
			new TransitionContext( array( $unpacked ) )
		);

		self::assertSame( 'all_items_packed', $result->rejection_code() );
	}

	public function test_photo_required_guard_is_the_third_check_and_only_reached_once_the_first_two_pass(): void {
		$packed = FulfillmentItem::intake( 1, 1, 1, 0, 'SKU', 'Widget', 2 );
		$packed->record_packed( 2 );

		$result = $this->engine->transition(
			$this->fulfillment_in( 'packing' ),
			'packed',
			StandardWorkflow::definition(),
			new TransitionContext( array( $packed ), true, false, false )
		);

		self::assertSame( 'photo_required', $result->rejection_code() );
	}

	public function test_rejects_an_edge_that_does_not_exist(): void {
		$result = $this->engine->transition(
			$this->fulfillment_in( 'queued' ),
			'shipped',
			StandardWorkflow::definition(),
			new TransitionContext()
		);

		self::assertFalse( $result->is_approved() );
		self::assertSame( 'no_such_edge', $result->rejection_code() );
	}

	public function test_rejects_an_unknown_target_state(): void {
		$result = $this->engine->transition(
			$this->fulfillment_in( 'queued' ),
			'not_a_real_state',
			StandardWorkflow::definition(),
			new TransitionContext()
		);

		self::assertSame( 'unknown_target_state', $result->rejection_code() );
	}

	public function test_rejects_an_unknown_current_state(): void {
		$fulfillment = Fulfillment::from_array(
			array(
				'id'                     => 1,
				'order_id'               => 1,
				'order_source'           => 'woocommerce',
				'warehouse_id'           => 1,
				'workflow'               => StandardWorkflow::NAME,
				'state'                  => 'gone_rogue',
				'previous_state'         => null,
				'return_to_state'        => null,
				'exception_reason'       => null,
				'priority'               => 0,
				'assignee_type'          => null,
				'assignee_id'            => null,
				'version'                => 1,
				'order_number_snapshot'  => '#1',
				'customer_name_snapshot' => 'Jane',
				'item_count'             => 1,
				'created_at'             => new DateTimeImmutable(),
				'state_entered_at'       => new DateTimeImmutable(),
				'completed_at'           => null,
			)
		);

		$result = $this->engine->transition( $fulfillment, 'queued', StandardWorkflow::definition(), new TransitionContext() );

		self::assertSame( 'unknown_current_state', $result->rejection_code() );
	}

	public function test_entering_an_exception_state_records_the_state_being_interrupted(): void {
		$result = $this->engine->transition(
			$this->fulfillment_in( 'picking' ),
			'problem',
			StandardWorkflow::definition(),
			new TransitionContext()
		);

		self::assertTrue( $result->is_approved() );
		self::assertSame( 'problem', $result->new_state() );
		self::assertSame( 'picking', $result->entering_exception_from() );
	}

	public function test_resolving_an_exception_state_is_approved_without_running_any_guard(): void {
		$fulfillment = $this->fulfillment_in( 'packing' );
		// Enter the exception, recording packing as the state to return to.
		$fulfillment->apply_transition( 'problem', 'packing', new DateTimeImmutable() );

		// Resolving back to packing must succeed even though packing's own
		// forward guards (all_items_packed etc.) are nowhere near satisfied
		// — resolution is not the packing->packed edge, it is a dynamic
		// return to whatever state the exception interrupted.
		$result = $this->engine->transition(
			$fulfillment,
			'packing',
			StandardWorkflow::definition(),
			new TransitionContext()
		);

		self::assertTrue( $result->is_approved() );
		self::assertSame( 'packing', $result->new_state() );
		self::assertNull( $result->entering_exception_from() );
	}

	public function test_resolving_to_a_state_other_than_return_to_state_is_rejected(): void {
		$fulfillment = $this->fulfillment_in( 'packing' );
		$fulfillment->apply_transition( 'problem', 'packing', new DateTimeImmutable() );

		// problem -> shipped is not a declared edge, and is not the
		// recorded return_to_state either — must fall through to a normal
		// no_such_edge rejection, not be treated as a resolution.
		$result = $this->engine->transition(
			$fulfillment,
			'shipped',
			StandardWorkflow::definition(),
			new TransitionContext()
		);

		self::assertFalse( $result->is_approved() );
		self::assertSame( 'no_such_edge', $result->rejection_code() );
	}

	public function test_cancellation_is_approved_from_a_non_terminal_state(): void {
		$result = $this->engine->transition(
			$this->fulfillment_in( 'picking' ),
			'cancelled',
			StandardWorkflow::definition(),
			new TransitionContext()
		);

		self::assertTrue( $result->is_approved() );
		self::assertSame( 'cancelled', $result->new_state() );
	}

	public function test_cancellation_is_rejected_from_a_terminal_state(): void {
		$result = $this->engine->transition(
			$this->fulfillment_in( 'completed' ),
			'cancelled',
			StandardWorkflow::definition(),
			new TransitionContext()
		);

		self::assertFalse( $result->is_approved() );
		self::assertSame( 'no_such_edge', $result->rejection_code() );
	}

	public function test_shortcut_edge_queued_to_packing_is_approved(): void {
		$result = $this->engine->transition(
			$this->fulfillment_in( 'queued' ),
			'packing',
			StandardWorkflow::definition(),
			new TransitionContext()
		);

		self::assertTrue( $result->is_approved() );
	}

	public function test_never_mutates_the_fulfillment_it_evaluates(): void {
		$fulfillment = $this->fulfillment_in( 'queued' );
		$before      = $fulfillment->to_array();

		$this->engine->transition( $fulfillment, 'picking', StandardWorkflow::definition(), new TransitionContext() );

		self::assertSame( $before, $fulfillment->to_array() );
	}
}
