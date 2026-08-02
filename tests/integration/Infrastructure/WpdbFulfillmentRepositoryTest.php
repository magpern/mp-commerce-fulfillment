<?php
/**
 * Integration tests for the fulfillment repository against a real database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Infrastructure;

use DateTimeImmutable;
use MPCF\Domain\Fulfillment;
use MPCF\Infrastructure\Database\Migrator;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use WP_UnitTestCase;

/**
 * Integration tests for the fulfillment repository against a real database.
 */
final class WpdbFulfillmentRepositoryTest extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var WpdbFulfillmentRepository
	 */
	private WpdbFulfillmentRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		( new Migrator() )->migrate();
		$this->repository = new WpdbFulfillmentRepository();
	}

	private function new_fulfillment( int $order_id = 1001 ): Fulfillment {
		return Fulfillment::intake( $order_id, 'woocommerce', 1, 'standard', 'queued', '#1001', 'Jane Doe', 2, new DateTimeImmutable( '2026-08-02 10:00:00' ) );
	}

	public function test_insert_assigns_an_id_and_find_returns_an_equivalent_fulfillment(): void {
		$id = $this->repository->insert( $this->new_fulfillment() );

		self::assertGreaterThan( 0, $id );

		$found = $this->repository->find( $id );

		self::assertNotNull( $found );
		self::assertSame( $id, $found->id() );
		self::assertSame( 1001, $found->order_id() );
		self::assertSame( 'woocommerce', $found->order_source() );
		self::assertSame( 'queued', $found->state() );
		self::assertSame( '#1001', $found->order_number_snapshot() );
		self::assertSame( 'Jane Doe', $found->customer_name_snapshot() );
		self::assertSame( 2, $found->item_count() );
		self::assertSame( 1, $found->version() );
		self::assertNull( $found->completed_at() );
	}

	public function test_find_returns_null_for_an_unknown_id(): void {
		self::assertNull( $this->repository->find( 999999 ) );
	}

	public function test_find_by_order_id_locates_the_right_row(): void {
		$this->repository->insert( $this->new_fulfillment( 2001 ) );
		$id = $this->repository->insert( $this->new_fulfillment( 2002 ) );

		$found = $this->repository->find_by_order_id( 2002 );

		self::assertNotNull( $found );
		self::assertSame( $id, $found->id() );
		self::assertSame( 2002, $found->order_id() );
	}

	public function test_find_by_order_id_returns_null_when_no_fulfillment_exists_for_that_order(): void {
		self::assertNull( $this->repository->find_by_order_id( 999999 ) );
	}

	public function test_save_persists_a_state_change_and_increments_version(): void {
		$id          = $this->repository->insert( $this->new_fulfillment() );
		$fulfillment = $this->repository->find( $id );

		$fulfillment->apply_transition( 'picking', null, new DateTimeImmutable( '2026-08-02 10:05:00' ) );

		$saved = $this->repository->save( $fulfillment );

		self::assertTrue( $saved );
		self::assertSame( 2, $fulfillment->version(), 'The in-memory version must advance on a successful save.' );

		$reloaded = $this->repository->find( $id );

		self::assertSame( 'picking', $reloaded->state() );
		self::assertSame( 'queued', $reloaded->previous_state() );
		self::assertSame( 2, $reloaded->version() );
	}

	public function test_save_persists_completed_at(): void {
		$id          = $this->repository->insert( $this->new_fulfillment() );
		$fulfillment = $this->repository->find( $id );
		$completed   = new DateTimeImmutable( '2026-08-03 12:00:00' );

		$fulfillment->apply_transition( 'completed', null, $completed );
		$this->repository->save( $fulfillment );

		$reloaded = $this->repository->find( $id );

		self::assertNotNull( $reloaded->completed_at() );
		self::assertSame( $completed->format( 'Y-m-d H:i:s' ), $reloaded->completed_at()->format( 'Y-m-d H:i:s' ) );
	}

	public function test_save_fails_on_a_version_conflict_and_does_not_persist_or_advance_the_stale_copy(): void {
		$id = $this->repository->insert( $this->new_fulfillment() );

		// Two independent copies of the same row, simulating two concurrent
		// requests both starting from version 1.
		$first_copy  = $this->repository->find( $id );
		$second_copy = $this->repository->find( $id );

		$first_copy->apply_transition( 'picking', null, new DateTimeImmutable() );
		self::assertTrue( $this->repository->save( $first_copy ), 'The first save, still at the version the row was loaded with, must succeed.' );

		$second_copy->apply_transition( 'packing', null, new DateTimeImmutable() );
		$conflict = $this->repository->save( $second_copy );

		self::assertFalse( $conflict, 'The second save, now stale, must be rejected.' );
		self::assertSame( 1, $second_copy->version(), 'A failed save must never advance the in-memory version.' );

		$reloaded = $this->repository->find( $id );
		self::assertSame( 'picking', $reloaded->state(), "The first writer's change must be the one that stuck." );
	}
}
