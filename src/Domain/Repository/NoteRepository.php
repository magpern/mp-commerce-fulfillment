<?php
/**
 * Persistence contract for internal fulfillment notes.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Repository;

use MPCF\Domain\Note;

/**
 * Implemented in Infrastructure, confined there per invariant I7.
 */
interface NoteRepository {

	/**
	 * Every note on one fulfillment.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @return list<Note>
	 */
	public function find_for_fulfillment( int $fulfillment_id ): array;

	/**
	 * Inserts a new note and returns its assigned id.
	 *
	 * @param Note $note Note to insert.
	 */
	public function insert( Note $note ): int;
}
