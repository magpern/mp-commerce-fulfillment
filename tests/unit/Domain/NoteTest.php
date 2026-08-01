<?php
/**
 * Tests for the fulfillment note entity.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain;

use DateTimeImmutable;
use MPCF\Domain\Note;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the fulfillment note entity.
 */
final class NoteTest extends TestCase {

	public function test_create_builds_an_unpinned_note_by_default(): void {
		$now  = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$note = Note::create( 1, 7, 'Customer called about delivery window.', $now );

		self::assertNull( $note->id() );
		self::assertSame( 1, $note->fulfillment_id() );
		self::assertSame( 7, $note->author_id() );
		self::assertSame( 'Customer called about delivery window.', $note->body() );
		self::assertFalse( $note->is_pinned() );
		self::assertSame( $now, $note->created_at() );
	}

	public function test_create_can_start_pinned(): void {
		$now  = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$note = Note::create( 1, 7, 'Fragile — handle with care.', $now, true );

		self::assertTrue( $note->is_pinned() );
	}

	public function test_pin_and_unpin(): void {
		$now  = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$note = Note::create( 1, 7, 'Body', $now );

		$note->pin();
		self::assertTrue( $note->is_pinned() );

		$note->unpin();
		self::assertFalse( $note->is_pinned() );
	}

	public function test_to_array_and_from_array_round_trip(): void {
		$now     = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$note    = Note::create( 1, 7, 'Body', $now, true );
		$rebuilt = Note::from_array( $note->to_array() );

		self::assertSame( $note->to_array(), $rebuilt->to_array() );
	}
}
