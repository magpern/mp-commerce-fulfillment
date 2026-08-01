<?php
/**
 * Tests for the workflow transition value object.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Workflow;

use MPCF\Domain\Workflow\Transition;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the workflow transition value object.
 */
final class TransitionTest extends TestCase {

	public function test_from_array_applies_documented_defaults(): void {
		$transition = Transition::from_array(
			array(
				'from'                => 'queued',
				'to'                  => 'picking',
				'required_capability' => 'mpcf_process_fulfillments',
			)
		);

		self::assertFalse( $transition->requires_reason() );
		self::assertSame( array(), $transition->guards() );
		self::assertSame( array(), $transition->events() );
	}

	public function test_to_array_and_from_array_round_trip(): void {
		$transition = Transition::from_array(
			array(
				'from'                => 'picking',
				'to'                  => 'problem',
				'required_capability' => 'mpcf_process_fulfillments',
				'requires_reason'     => true,
				'guards'              => array( 'all_items_picked' ),
				'events'              => array( 'fulfillment.state_changed' ),
			)
		);

		self::assertSame( $transition->to_array(), Transition::from_array( $transition->to_array() )->to_array() );
	}
}
