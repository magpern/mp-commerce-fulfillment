<?php
/**
 * Integration tests for the fulfillment note repository against a real
 * database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Infrastructure;

use DateTimeImmutable;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\Note;
use MPCF\Infrastructure\Database\Migrator;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\Database\WpdbNoteRepository;
use WP_UnitTestCase;

/**
 * Integration tests for the fulfillment note repository against a real
 * database.
 */
final class WpdbNoteRepositoryTest extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var WpdbNoteRepository
	 */
	private WpdbNoteRepository $repository;

	/**
	 * Owning fulfillment's id, created fresh per test.
	 *
	 * @var int
	 */
	private int $fulfillment_id;

	protected function setUp(): void {
		parent::setUp();
		( new Migrator() )->migrate();
		$this->repository     = new WpdbNoteRepository();
		$this->fulfillment_id = ( new WpdbFulfillmentRepository() )->insert(
			Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'queued', '#1001', 'Jane Doe', 1, new DateTimeImmutable() )
		);
	}

	public function test_insert_assigns_an_id_and_find_for_fulfillment_returns_it(): void {
		$id = $this->repository->insert( Note::create( $this->fulfillment_id, 7, 'Customer called.', new DateTimeImmutable() ) );

		self::assertGreaterThan( 0, $id );

		$notes = $this->repository->find_for_fulfillment( $this->fulfillment_id );

		self::assertCount( 1, $notes );
		self::assertSame( $id, $notes[0]->id() );
		self::assertSame( 'Customer called.', $notes[0]->body() );
		self::assertSame( 7, $notes[0]->author_id() );
		self::assertFalse( $notes[0]->is_pinned() );
	}

	public function test_find_for_fulfillment_returns_an_empty_list_when_none_exist(): void {
		self::assertSame( array(), $this->repository->find_for_fulfillment( $this->fulfillment_id ) );
	}

	public function test_find_for_fulfillment_orders_pinned_notes_first_then_newest_first(): void {
		$this->repository->insert( Note::create( $this->fulfillment_id, 7, 'Oldest, unpinned.', new DateTimeImmutable( '2026-08-01 10:00:00' ) ) );
		$this->repository->insert( Note::create( $this->fulfillment_id, 7, 'Newest, unpinned.', new DateTimeImmutable( '2026-08-02 10:00:00' ) ) );
		$this->repository->insert( Note::create( $this->fulfillment_id, 7, 'Pinned.', new DateTimeImmutable( '2026-07-30 10:00:00' ), true ) );

		$notes = $this->repository->find_for_fulfillment( $this->fulfillment_id );

		self::assertSame( 'Pinned.', $notes[0]->body(), 'Pinned notes must sort first regardless of age.' );
		self::assertSame( 'Newest, unpinned.', $notes[1]->body() );
		self::assertSame( 'Oldest, unpinned.', $notes[2]->body() );
	}

	public function test_find_for_fulfillment_does_not_return_notes_belonging_to_another_fulfillment(): void {
		$other_fulfillment_id = ( new WpdbFulfillmentRepository() )->insert(
			Fulfillment::intake( 1002, 'woocommerce', 1, 'standard', 'queued', '#1002', 'John Doe', 1, new DateTimeImmutable() )
		);

		$this->repository->insert( Note::create( $this->fulfillment_id, 7, 'Mine.', new DateTimeImmutable() ) );
		$this->repository->insert( Note::create( $other_fulfillment_id, 7, 'Not mine.', new DateTimeImmutable() ) );

		$notes = $this->repository->find_for_fulfillment( $this->fulfillment_id );

		self::assertCount( 1, $notes );
		self::assertSame( 'Mine.', $notes[0]->body() );
	}
}
