<?php
/**
 * Application-layer facade for internal fulfillment notes.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use MPCF\Domain\Clock;
use MPCF\Domain\Note;
use MPCF\Domain\Repository\NoteRepository;

/**
 * Admin never calls {@see NoteRepository} directly (invariant I11,
 * `AdminBoundaryGuardTest`).
 */
final class NoteService {

	/**
	 * Note persistence.
	 *
	 * @var NoteRepository
	 */
	private NoteRepository $notes;

	/**
	 * Source of "now".
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Builds the service.
	 *
	 * @param NoteRepository $notes Note persistence.
	 * @param Clock          $clock Source of "now".
	 */
	public function __construct( NoteRepository $notes, Clock $clock ) {
		$this->notes = $notes;
		$this->clock = $clock;
	}

	/**
	 * Adds a note to a fulfillment.
	 *
	 * @param int    $fulfillment_id Fulfillment the note belongs to.
	 * @param int    $author_id      Author's user id.
	 * @param string $body           Note text.
	 * @param bool   $is_pinned      Whether to pin it.
	 */
	public function add( int $fulfillment_id, int $author_id, string $body, bool $is_pinned = false ): Note {
		$note = Note::create( $fulfillment_id, $author_id, $body, $this->clock->now(), $is_pinned );
		$id   = $this->notes->insert( $note );

		return Note::from_array( array( 'id' => $id ) + $note->to_array() );
	}

	/**
	 * Every note on a fulfillment, pinned first.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @return list<Note>
	 */
	public function list_for( int $fulfillment_id ): array {
		return $this->notes->find_for_fulfillment( $fulfillment_id );
	}
}
