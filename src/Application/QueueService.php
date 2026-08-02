<?php
/**
 * Read-side facade for the Fulfillment Queue.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use MPCF\Domain\FulfillmentQuery;
use MPCF\Domain\FulfillmentQueryResult;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Domain\SearchQuery;

/**
 * The only thing the Admin layer calls to list/filter/search fulfillments —
 * Admin never talks to {@see FulfillmentRepository} or {@see SearchQuery}
 * directly (invariant I11, `AdminBoundaryGuardTest`). A non-empty search
 * term is resolved to a fulfillment-id filter before the repository query
 * runs, so the Queue's other filters (state, assignee, age) and a search
 * term combine as a single indexed lookup, never two separate result sets
 * merged in PHP.
 */
final class QueueService {

	/**
	 * Fulfillment listing.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

	/**
	 * Free-text search resolution.
	 *
	 * @var SearchQuery
	 */
	private SearchQuery $search;

	/**
	 * Builds the service.
	 *
	 * @param FulfillmentRepository $fulfillments Fulfillment listing.
	 * @param SearchQuery           $search       Free-text search resolution.
	 */
	public function __construct( FulfillmentRepository $fulfillments, SearchQuery $search ) {
		$this->fulfillments = $fulfillments;
		$this->search       = $search;
	}

	/**
	 * Lists fulfillments matching `$query`, optionally narrowed further by
	 * a free-text `$search_term`.
	 *
	 * @param FulfillmentQuery $query       Filter/sort/page.
	 * @param string|null      $search_term Optional free-text term.
	 */
	public function list( FulfillmentQuery $query, ?string $search_term = null ): FulfillmentQueryResult {
		$search_term = null === $search_term ? '' : trim( $search_term );

		if ( '' !== $search_term ) {
			$query = new FulfillmentQuery(
				$query->states(),
				$query->assignee(),
				$this->search->search( $search_term ),
				$query->min_age_seconds(),
				$query->order_by(),
				$query->order(),
				$query->page(),
				$query->per_page()
			);
		}

		return $this->fulfillments->query( $query );
	}
}
