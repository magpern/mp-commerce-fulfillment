<?php
/**
 * Integration tests for the append-only audit event repository against a
 * real database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Infrastructure;

use DateTimeImmutable;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\Canonicalizer;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\Fulfillment;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use WP_UnitTestCase;

/**
 * Integration tests for the append-only audit event repository against a
 * real database.
 */
final class WpdbEventRepositoryTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;

	/**
	 * Repository under test.
	 *
	 * @var WpdbEventRepository
	 */
	private WpdbEventRepository $repository;

	/**
	 * Owning fulfillment's id, created fresh per test.
	 *
	 * @var int
	 */
	private int $fulfillment_id;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables(); // Ensures the tables exist and are empty; see the trait's docblock.
		$this->repository     = new WpdbEventRepository();
		$this->fulfillment_id = ( new WpdbFulfillmentRepository() )->insert(
			Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'queued', '#1001', 'Jane Doe', 1, new DateTimeImmutable() )
		);
	}

	public function test_last_hash_for_fulfillment_is_null_before_any_event(): void {
		self::assertNull( $this->repository->last_hash_for_fulfillment( $this->fulfillment_id ) );
	}

	public function test_append_returns_the_hash_computed_from_the_given_prev_hash_and_payload(): void {
		$event = DomainEvent::for_fulfillment( $this->fulfillment_id, 'fulfillment.state_changed', Actor::system(), new DateTimeImmutable(), array( 'to' => 'picking' ) );

		$hash = $this->repository->append( $event, null );

		self::assertSame( Canonicalizer::hash( null, $event->hashable_payload() ), $hash );
	}

	public function test_last_hash_for_fulfillment_tracks_the_most_recently_appended_event(): void {
		$now = new DateTimeImmutable();

		$first_hash  = $this->repository->append( DomainEvent::for_fulfillment( $this->fulfillment_id, 'fulfillment.state_changed', Actor::system(), $now, array( 'to' => 'picking' ) ), null );
		$second_hash = $this->repository->append( DomainEvent::for_fulfillment( $this->fulfillment_id, 'fulfillment.state_changed', Actor::system(), $now, array( 'to' => 'picked' ) ), $first_hash );

		self::assertSame( $second_hash, $this->repository->last_hash_for_fulfillment( $this->fulfillment_id ) );
		self::assertNotSame( $first_hash, $second_hash );
	}

	public function test_timeline_for_fulfillment_returns_events_oldest_first_with_decoded_payload(): void {
		$now = new DateTimeImmutable();

		$first_hash = $this->repository->append(
			DomainEvent::for_fulfillment( $this->fulfillment_id, 'fulfillment.state_changed', Actor::user( 7, 'Jane' ), $now, array( 'to' => 'picking' ) ),
			null
		);
		$this->repository->append(
			DomainEvent::for_fulfillment( $this->fulfillment_id, 'fulfillment.state_changed', Actor::user( 7, 'Jane' ), $now, array( 'to' => 'picked' ) ),
			$first_hash
		);

		$timeline = $this->repository->timeline_for_fulfillment( $this->fulfillment_id );

		self::assertCount( 2, $timeline );
		self::assertSame( 'picking', $timeline[0]['payload']['to'] );
		self::assertSame( 'picked', $timeline[1]['payload']['to'] );
		self::assertSame( 'Jane', $timeline[0]['actor_label_snapshot'] );
		self::assertNull( $timeline[0]['prev_hash'] );
		self::assertSame( $first_hash, $timeline[0]['hash'] );
		self::assertSame( $first_hash, $timeline[1]['prev_hash'], "The second event's prev_hash must chain to the first event's hash." );
	}

	public function test_timeline_for_fulfillment_does_not_return_events_for_another_fulfillment(): void {
		$other_fulfillment_id = ( new WpdbFulfillmentRepository() )->insert(
			Fulfillment::intake( 1002, 'woocommerce', 1, 'standard', 'queued', '#1002', 'John Doe', 1, new DateTimeImmutable() )
		);

		$this->repository->append( DomainEvent::for_fulfillment( $this->fulfillment_id, 'fulfillment.state_changed', Actor::system(), new DateTimeImmutable() ), null );
		$this->repository->append( DomainEvent::for_fulfillment( $other_fulfillment_id, 'fulfillment.state_changed', Actor::system(), new DateTimeImmutable() ), null );

		self::assertCount( 1, $this->repository->timeline_for_fulfillment( $this->fulfillment_id ) );
	}

	public function test_append_persists_a_global_event_with_a_null_fulfillment_id(): void {
		$event = DomainEvent::global_event( 'settings.changed', Actor::user( 1, 'Admin' ), new DateTimeImmutable() );

		$hash = $this->repository->append( $event, null );

		self::assertNotSame( '', $hash );
		self::assertSame( array(), $this->repository->timeline_for_fulfillment( $this->fulfillment_id ), 'A global event must not appear in any fulfillment-scoped timeline.' );
	}

	/**
	 * Appends `$count` bare `fulfillment.state_changed` events, chained,
	 * `payload.seq` numbered from 0 — the shared fixture for the paginated
	 * and "most recent" reader tests below.
	 *
	 * @param int $count Number of events to append.
	 */
	private function append_sequence( int $count ): void {
		$prev_hash = null;

		for ( $i = 0; $i < $count; $i++ ) {
			$prev_hash = $this->repository->append(
				DomainEvent::for_fulfillment( $this->fulfillment_id, 'fulfillment.state_changed', Actor::system(), new DateTimeImmutable(), array( 'seq' => $i ) ),
				$prev_hash
			);
		}
	}

	public function test_timeline_page_for_fulfillment_returns_one_page_oldest_first_with_a_correct_total(): void {
		$this->append_sequence( 7 );

		$page = $this->repository->timeline_page_for_fulfillment( $this->fulfillment_id, 1, 3 );

		self::assertSame( 7, $page->total() );
		self::assertSame( 1, $page->page() );
		self::assertSame( 3, $page->per_page() );
		self::assertSame( 3, $page->total_pages() );
		self::assertCount( 3, $page->items() );
		self::assertSame( array( 0, 1, 2 ), array_column( array_column( $page->items(), 'payload' ), 'seq' ) );
	}

	public function test_timeline_page_for_fulfillment_returns_the_remainder_on_the_last_page(): void {
		$this->append_sequence( 7 );

		$page = $this->repository->timeline_page_for_fulfillment( $this->fulfillment_id, 3, 3 );

		self::assertCount( 1, $page->items(), 'The last page of an uneven total must hold only the remainder, not pad or overflow.' );
		self::assertSame( 6, $page->items()[0]['payload']['seq'] );
	}

	public function test_timeline_page_for_fulfillment_does_not_include_events_for_another_fulfillment(): void {
		$other_fulfillment_id = ( new WpdbFulfillmentRepository() )->insert(
			Fulfillment::intake( 1003, 'woocommerce', 1, 'standard', 'queued', '#1003', 'Jo Doe', 1, new DateTimeImmutable() )
		);

		$this->append_sequence( 2 );
		$this->repository->append( DomainEvent::for_fulfillment( $other_fulfillment_id, 'fulfillment.state_changed', Actor::system(), new DateTimeImmutable() ), null );

		$page = $this->repository->timeline_page_for_fulfillment( $this->fulfillment_id, 1, 20 );

		self::assertSame( 2, $page->total() );
	}

	public function test_recent_for_fulfillment_returns_exactly_the_limit_oldest_first_among_themselves(): void {
		$this->append_sequence( 7 );

		$recent = $this->repository->recent_for_fulfillment( $this->fulfillment_id, 5 );

		self::assertCount( 5, $recent );
		self::assertSame( array( 2, 3, 4, 5, 6 ), array_column( array_column( $recent, 'payload' ), 'seq' ), 'Must be the 5 most recent, oldest-first among themselves — not "whichever page-of-5 happens to be last".' );
	}

	public function test_recent_for_fulfillment_returns_every_row_when_fewer_than_the_limit_exist(): void {
		$this->append_sequence( 3 );

		$recent = $this->repository->recent_for_fulfillment( $this->fulfillment_id, 5 );

		self::assertCount( 3, $recent );
		self::assertSame( array( 0, 1, 2 ), array_column( array_column( $recent, 'payload' ), 'seq' ) );
	}
}
