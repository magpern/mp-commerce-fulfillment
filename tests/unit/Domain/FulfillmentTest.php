<?php
/**
 * Tests for the fulfillment aggregate root.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain;

use DateTimeImmutable;
use MPCF\Domain\Fulfillment;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the fulfillment aggregate root.
 */
final class FulfillmentTest extends TestCase {

	public function test_intake_builds_a_fulfillment_in_the_initial_state_with_version_one(): void {
		$now         = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$fulfillment = Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'queued', '#1001', 'Jane Doe', 3, $now );

		self::assertNull( $fulfillment->id() );
		self::assertSame( 1001, $fulfillment->order_id() );
		self::assertSame( 'woocommerce', $fulfillment->order_source() );
		self::assertSame( 1, $fulfillment->warehouse_id() );
		self::assertSame( 'standard', $fulfillment->workflow() );
		self::assertSame( 'queued', $fulfillment->state() );
		self::assertNull( $fulfillment->previous_state() );
		self::assertNull( $fulfillment->return_to_state() );
		self::assertNull( $fulfillment->exception_reason() );
		self::assertSame( 0, $fulfillment->priority() );
		self::assertNull( $fulfillment->assignee_type() );
		self::assertNull( $fulfillment->assignee_id() );
		self::assertSame( 1, $fulfillment->version() );
		self::assertSame( '#1001', $fulfillment->order_number_snapshot() );
		self::assertSame( 'Jane Doe', $fulfillment->customer_name_snapshot() );
		self::assertSame( 3, $fulfillment->item_count() );
		self::assertSame( $now, $fulfillment->created_at() );
		self::assertSame( $now, $fulfillment->state_entered_at() );
		self::assertNull( $fulfillment->completed_at() );
	}

	public function test_to_array_and_from_array_round_trip(): void {
		$now         = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$fulfillment = Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'queued', '#1001', 'Jane Doe', 3, $now );

		$rebuilt = Fulfillment::from_array( $fulfillment->to_array() );

		self::assertSame( $fulfillment->to_array(), $rebuilt->to_array() );
	}

	public function test_from_array_assigns_id_when_present(): void {
		$now        = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$data       = Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'queued', '#1001', 'Jane Doe', 3, $now )->to_array();
		$data['id'] = 42;

		$fulfillment = Fulfillment::from_array( $data );

		self::assertSame( 42, $fulfillment->id() );
	}

	public function test_apply_transition_records_previous_state_and_advances_state_entered_at(): void {
		$created = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$later   = new DateTimeImmutable( '2026-08-02 10:05:00' );

		$fulfillment = Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'queued', '#1001', 'Jane Doe', 3, $created );
		$fulfillment->apply_transition( 'picking', null, $later );

		self::assertSame( 'picking', $fulfillment->state() );
		self::assertSame( 'queued', $fulfillment->previous_state() );
		self::assertNull( $fulfillment->return_to_state() );
		self::assertSame( $later, $fulfillment->state_entered_at() );
		self::assertNull( $fulfillment->completed_at() );
	}

	public function test_apply_transition_into_an_exception_state_records_return_to_state_and_clears_it_on_resolve(): void {
		$now         = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$fulfillment = Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'queued', '#1001', 'Jane Doe', 3, $now );

		$fulfillment->apply_transition( 'picking', null, $now );
		$fulfillment->apply_transition( 'problem', 'picking', $now );
		$fulfillment->set_exception_reason( 'Address dispute' );

		self::assertSame( 'problem', $fulfillment->state() );
		self::assertSame( 'picking', $fulfillment->return_to_state() );
		self::assertSame( 'Address dispute', $fulfillment->exception_reason() );

		// Resolving passes null for $entering_exception_from — clears both
		// return_to_state and the reason that went with it.
		$fulfillment->apply_transition( 'picking', null, $now );

		self::assertSame( 'picking', $fulfillment->state() );
		self::assertNull( $fulfillment->return_to_state() );
		self::assertNull( $fulfillment->exception_reason() );
	}

	public function test_completed_at_is_set_once_on_first_entry_to_completed_and_never_overwritten(): void {
		$first_completion  = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$second_transition = new DateTimeImmutable( '2026-08-03 10:00:00' );

		$fulfillment = Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'shipped', '#1001', 'Jane Doe', 3, $first_completion );
		$fulfillment->apply_transition( 'completed', null, $first_completion );

		self::assertSame( $first_completion, $fulfillment->completed_at() );

		// A later transition into a non-completed state, then back, must not
		// move completed_at — it records the *first* completion only.
		$fulfillment->apply_transition( 'problem', 'completed', $second_transition );
		$fulfillment->apply_transition( 'completed', null, $second_transition );

		self::assertSame( $first_completion, $fulfillment->completed_at() );
	}

	public function test_assign_and_unassign(): void {
		$now         = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$fulfillment = Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'queued', '#1001', 'Jane Doe', 3, $now );

		$fulfillment->assign( 'user', 7 );
		self::assertSame( 'user', $fulfillment->assignee_type() );
		self::assertSame( 7, $fulfillment->assignee_id() );

		$fulfillment->unassign();
		self::assertNull( $fulfillment->assignee_type() );
		self::assertNull( $fulfillment->assignee_id() );
	}

	public function test_set_priority(): void {
		$now         = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$fulfillment = Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'queued', '#1001', 'Jane Doe', 3, $now );

		$fulfillment->set_priority( 5 );

		self::assertSame( 5, $fulfillment->priority() );
	}

	public function test_increment_version(): void {
		$now         = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$fulfillment = Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'queued', '#1001', 'Jane Doe', 3, $now );

		$fulfillment->increment_version();
		$fulfillment->increment_version();

		self::assertSame( 3, $fulfillment->version() );
	}
}
