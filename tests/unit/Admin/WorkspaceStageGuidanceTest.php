<?php
/**
 * Unit tests for WorkspaceStageGuidance presentation mapping.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Admin;

use MPCF\Admin\WorkspaceStageGuidance;
use MPCF\Application\AvailableTransition;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Engine\TransitionResult;
use PHPUnit\Framework\TestCase;

/**
 * Admin presentation helper — WordPress stubs load via the unit bootstrap
 * so `__()` is available without a full WP install.
 */
final class WorkspaceStageGuidanceTest extends TestCase {

	public function test_queued_guidance_includes_start_picking_instruction(): void {
		$guidance = WorkspaceStageGuidance::for_state( 'queued', null, StandardWorkflow::definition() );

		self::assertSame( 'queued', $guidance['state_key'] );
		self::assertStringContainsString( 'Start picking', $guidance['instruction'] );
		self::assertSame( 'muted', $guidance['shipment_emphasis'] );
	}

	public function test_unknown_state_degrades_safely(): void {
		$guidance = WorkspaceStageGuidance::for_state( 'custom_hold', null, StandardWorkflow::definition() );

		self::assertSame( 'custom_hold', $guidance['state_key'] );
		self::assertSame( 'custom_hold', $guidance['state_label'] );
		self::assertStringContainsString( 'Review this fulfillment', $guidance['instruction'] );
		self::assertSame( 'secondary', $guidance['shipment_emphasis'] );
	}

	public function test_primary_transition_label_overrides_default_next_action(): void {
		$primary = AvailableTransition::from_result(
			'picking',
			'Begin pick run',
			TransitionResult::approved( 'picking', null, array() ),
			false,
			'mpcf_process_fulfillments'
		);

		$guidance = WorkspaceStageGuidance::for_state( 'queued', $primary, StandardWorkflow::definition() );

		self::assertSame( 'Begin pick run', $guidance['next_action_label'] );
	}

	public function test_operator_guard_message_maps_known_codes(): void {
		self::assertSame(
			'Pick all ordered items before marking this fulfillment as picked.',
			WorkspaceStageGuidance::operator_guard_message( 'all_items_picked', 'Every line item must be fully picked first.' )
		);
		self::assertSame(
			'Enter a tracking number before shipping.',
			WorkspaceStageGuidance::operator_guard_message( 'has_tracking', 'ignored' )
		);
	}

	public function test_operator_guard_message_falls_back_to_engine_text(): void {
		self::assertSame(
			'Custom engine message.',
			WorkspaceStageGuidance::operator_guard_message( 'unknown_guard', 'Custom engine message.' )
		);
	}

	public function test_shipment_section_open_for_packing_stages_only(): void {
		self::assertFalse( WorkspaceStageGuidance::shipment_section_open( 'queued' ) );
		self::assertFalse( WorkspaceStageGuidance::shipment_section_open( 'picking' ) );
		self::assertTrue( WorkspaceStageGuidance::shipment_section_open( 'packing' ) );
		self::assertTrue( WorkspaceStageGuidance::shipment_section_open( 'packed' ) );
	}
}
