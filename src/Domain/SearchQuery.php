<?php
/**
 * Port to the Queue's free-text search backend.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

/**
 * Architecture Plan §9.3/D22: the Queue UI never changes when the search
 * backend evolves. The v1 implementation ({@see \MPCF\Infrastructure\Database\WpdbSearchQuery})
 * classifies the term ({@see SearchTermClassifier}) and unions targeted
 * indexed lookups — never an unindexed `LIKE '%…%'` scan. A denormalized
 * `mpcf_search_index` projection is reserved for a future milestone only if
 * profiling shows this approach failing at scale; this port is what makes
 * that swap invisible to every caller.
 */
interface SearchQuery {

	/**
	 * Every fulfillment id matching a search term. Never called with an
	 * empty term.
	 *
	 * @param string $term Raw search term.
	 * @return list<int>
	 */
	public function search( string $term ): array;
}
