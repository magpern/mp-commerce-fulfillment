<?php
/**
 * Read-side facade for the Fulfillment Detail screen.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use MPCF\Domain\Repository\EventRepository;
use MPCF\Domain\Repository\FulfillmentItemRepository;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Domain\Repository\NoteRepository;

/**
 * Aggregates a fulfillment with its items, full audit timeline and notes —
 * Admin never assembles this from individual repositories itself
 * (invariant I11, `AdminBoundaryGuardTest`).
 */
final class FulfillmentDetailService {

	/**
	 * Fulfillment lookup.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

	/**
	 * Line items.
	 *
	 * @var FulfillmentItemRepository
	 */
	private FulfillmentItemRepository $items;

	/**
	 * Audit timeline.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * Notes.
	 *
	 * @var NoteRepository
	 */
	private NoteRepository $notes;

	/**
	 * Builds the service.
	 *
	 * @param FulfillmentRepository     $fulfillments Fulfillment lookup.
	 * @param FulfillmentItemRepository $items        Line items.
	 * @param EventRepository           $events       Audit timeline.
	 * @param NoteRepository            $notes        Notes.
	 */
	public function __construct(
		FulfillmentRepository $fulfillments,
		FulfillmentItemRepository $items,
		EventRepository $events,
		NoteRepository $notes
	) {
		$this->fulfillments = $fulfillments;
		$this->items        = $items;
		$this->events       = $events;
		$this->notes        = $notes;
	}

	/**
	 * Assembles the full detail view for one fulfillment, or null if it
	 * does not exist.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function get( int $fulfillment_id ): ?FulfillmentDetailView {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return null;
		}

		return new FulfillmentDetailView(
			$fulfillment,
			$this->items->find_for_fulfillment( $fulfillment_id ),
			$this->events->timeline_for_fulfillment( $fulfillment_id ),
			$this->notes->find_for_fulfillment( $fulfillment_id )
		);
	}
}
