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
use MPCF\Infrastructure\Database\Migrator;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use WP_UnitTestCase;

/**
 * Integration tests for the append-only audit event repository against a
 * real database.
 */
final class WpdbEventRepositoryTest extends WP_UnitTestCase {

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
		( new Migrator() )->migrate();
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
}
