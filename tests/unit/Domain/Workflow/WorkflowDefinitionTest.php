<?php
/**
 * Tests for the workflow definition value object, including its
 * structural validation.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Workflow;

use InvalidArgumentException;
use MPCF\Domain\Workflow\State;
use MPCF\Domain\Workflow\WorkflowDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the workflow definition value object, including its structural validation.
 */
final class WorkflowDefinitionTest extends TestCase {

	private function minimal_valid_data(): array {
		return array(
			'name'          => 'test',
			'version'       => 1,
			'initial_state' => 'start',
			'states'        => array(
				array(
					'key'   => 'start',
					'label' => 'Start',
					'type'  => State::TYPE_INITIAL,
				),
				array(
					'key'   => 'end',
					'label' => 'End',
					'type'  => State::TYPE_TERMINAL,
				),
			),
			'transitions'   => array(
				array(
					'from'                => 'start',
					'to'                  => 'end',
					'required_capability' => 'mpcf_process_fulfillments',
				),
			),
		);
	}

	public function test_from_array_builds_a_valid_definition(): void {
		$definition = WorkflowDefinition::from_array( $this->minimal_valid_data() );

		self::assertSame( 'test', $definition->name() );
		self::assertSame( 1, $definition->version() );
		self::assertSame( 'start', $definition->initial_state() );
		self::assertCount( 2, $definition->states() );
		self::assertTrue( $definition->has_state( 'start' ) );
		self::assertNotNull( $definition->transition( 'start', 'end' ) );
		self::assertNull( $definition->transition( 'end', 'start' ) );
	}

	public function test_to_array_and_from_array_round_trip(): void {
		$definition = WorkflowDefinition::from_array( $this->minimal_valid_data() );

		self::assertSame( $definition->to_array(), WorkflowDefinition::from_array( $definition->to_array() )->to_array() );
	}

	public function test_transitions_from_returns_only_matching_edges_in_order(): void {
		$data                  = $this->minimal_valid_data();
		$data['states'][]      = array(
			'key'   => 'middle',
			'label' => 'Middle',
			'type'  => State::TYPE_WORKING,
		);
		$data['transitions'][] = array(
			'from'                => 'start',
			'to'                  => 'middle',
			'required_capability' => 'mpcf_process_fulfillments',
		);
		$data['transitions'][] = array(
			'from'                => 'middle',
			'to'                  => 'end',
			'required_capability' => 'mpcf_process_fulfillments',
		);

		$definition = WorkflowDefinition::from_array( $data );
		$from_start = $definition->transitions_from( 'start' );

		self::assertCount( 2, $from_start );
		self::assertSame( 'end', $from_start[0]->to() );
		self::assertSame( 'middle', $from_start[1]->to() );
	}

	public function test_rejects_zero_initial_states(): void {
		$data                      = $this->minimal_valid_data();
		$data['states'][0]['type'] = State::TYPE_WORKING;

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'exactly one initial state' );

		WorkflowDefinition::from_array( $data );
	}

	public function test_rejects_multiple_initial_states(): void {
		$data                      = $this->minimal_valid_data();
		$data['states'][1]['type'] = State::TYPE_INITIAL;

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'exactly one initial state' );

		WorkflowDefinition::from_array( $data );
	}

	public function test_rejects_declared_initial_state_mismatching_the_initial_type_state(): void {
		$data                  = $this->minimal_valid_data();
		$data['initial_state'] = 'end';

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'initial_state must match' );

		WorkflowDefinition::from_array( $data );
	}

	public function test_rejects_no_terminal_state(): void {
		$data                      = $this->minimal_valid_data();
		$data['states'][1]['type'] = State::TYPE_WORKING;
		$data['transitions']       = array();

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'at least one terminal state' );

		WorkflowDefinition::from_array( $data );
	}

	public function test_rejects_transition_referencing_an_undeclared_state(): void {
		$data                  = $this->minimal_valid_data();
		$data['transitions'][] = array(
			'from'                => 'end',
			'to'                  => 'nowhere',
			'required_capability' => 'mpcf_process_fulfillments',
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'undeclared state "nowhere"' );

		WorkflowDefinition::from_array( $data );
	}

	public function test_rejects_an_orphan_state_reachable_by_no_transition(): void {
		$data             = $this->minimal_valid_data();
		$data['states'][] = array(
			'key'   => 'orphan',
			'label' => 'Orphan',
			'type'  => State::TYPE_TERMINAL,
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'orphan state "orphan"' );

		WorkflowDefinition::from_array( $data );
	}

	public function test_state_throws_for_an_undeclared_key(): void {
		$definition = WorkflowDefinition::from_array( $this->minimal_valid_data() );

		$this->expectException( InvalidArgumentException::class );

		$definition->state( 'nowhere' );
	}
}
