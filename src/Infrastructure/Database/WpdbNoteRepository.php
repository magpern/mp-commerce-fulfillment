<?php
/**
 * Database-backed fulfillment note repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

use DateTimeImmutable;
use MPCF\Domain\Note;
use MPCF\Domain\Repository\NoteRepository;

/**
 * The only class that reads or writes `mpcf_notes`.
 */
final class WpdbNoteRepository implements NoteRepository {

	/**
	 * Every note on one fulfillment, pinned first, then newest first.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function find_for_fulfillment( int $fulfillment_id ): array {
		global $wpdb;

		$table = Schema::table( Schema::NOTES );
		$rows  = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE fulfillment_id = %d ORDER BY is_pinned DESC, created_at DESC, id DESC", $fulfillment_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows ?? array() );
	}

	/**
	 * Inserts a new note and returns its assigned id.
	 *
	 * @param Note $note Note to insert.
	 */
	public function insert( Note $note ): int {
		global $wpdb;

		$table = Schema::table( Schema::NOTES );

		$wpdb->insert(
			$table,
			array(
				'fulfillment_id' => $note->fulfillment_id(),
				'author_id'      => $note->author_id(),
				'body'           => $note->body(),
				'is_pinned'      => $note->is_pinned() ? 1 : 0,
				'created_at'     => $note->created_at()->format( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Assembles a note from one `ARRAY_A` row.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 */
	private function hydrate( array $row ): Note {
		return Note::from_array(
			array(
				'id'             => (int) $row['id'],
				'fulfillment_id' => (int) $row['fulfillment_id'],
				'author_id'      => (int) $row['author_id'],
				'body'           => (string) $row['body'],
				'is_pinned'      => (bool) (int) $row['is_pinned'],
				'created_at'     => new DateTimeImmutable( (string) $row['created_at'] ),
			)
		);
	}
}
