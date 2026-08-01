<?php
/**
 * Persistence contract for fulfillment line items.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Repository;

use MPCF\Domain\FulfillmentItem;

/**
 * Implemented in Infrastructure, confined there per invariant I7.
 */
interface FulfillmentItemRepository {

	/**
	 * Every line item belonging to one fulfillment.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @return list<FulfillmentItem>
	 */
	public function find_for_fulfillment( int $fulfillment_id ): array;

	/**
	 * Inserts every item for a fulfillment at intake time. Items are
	 * created once, at intake, and mutated in place thereafter (picked/
	 * packed quantities) — there is no general-purpose add/remove after
	 * that point in Milestone 1.
	 *
	 * @param array<int, FulfillmentItem> $items Items to insert.
	 */
	public function insert_all( array $items ): void;

	/**
	 * Persists a mutation to an existing item (picked/packed quantities).
	 *
	 * @param FulfillmentItem $item Item to persist.
	 */
	public function save( FulfillmentItem $item ): void;
}
