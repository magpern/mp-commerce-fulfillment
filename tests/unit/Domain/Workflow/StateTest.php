<?php
/**
 * Tests for the workflow state value object.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Workflow;

use MPCF\Domain\Workflow\State;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the workflow state value object.
 */
final class StateTest extends TestCase {

	public function test_from_array_applies_documented_defaults(): void {
		$state = State::from_array(
			array(
				'key'   => 'queued',
				'label' => 'Queued',
				'type'  => State::TYPE_INITIAL,
			)
		);

		self::assertSame( 'disabled', $state->badge_variant() );
		self::assertFalse( $state->counts_as_open() );
		self::assertFalse( $state->expects_operator() );
	}

	public function test_type_predicates(): void {
		self::assertTrue(
			State::from_array(
				array(
					'key'   => 'a',
					'label' => 'A',
					'type'  => State::TYPE_INITIAL,
				)
			)->is_initial()
		);
		self::assertTrue(
			State::from_array(
				array(
					'key'   => 'a',
					'label' => 'A',
					'type'  => State::TYPE_TERMINAL,
				)
			)->is_terminal()
		);
		self::assertTrue(
			State::from_array(
				array(
					'key'   => 'a',
					'label' => 'A',
					'type'  => State::TYPE_EXCEPTION,
				)
			)->is_exception()
		);
		self::assertFalse(
			State::from_array(
				array(
					'key'   => 'a',
					'label' => 'A',
					'type'  => State::TYPE_WORKING,
				)
			)->is_initial()
		);
	}

	public function test_to_array_and_from_array_round_trip(): void {
		$state = State::from_array(
			array(
				'key'              => 'picking',
				'label'            => 'Picking',
				'badge_variant'    => 'warning',
				'type'             => State::TYPE_WORKING,
				'counts_as_open'   => true,
				'expects_operator' => true,
			)
		);

		self::assertSame( $state->to_array(), State::from_array( $state->to_array() )->to_array() );
	}
}
