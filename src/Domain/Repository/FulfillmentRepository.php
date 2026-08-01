<?php
/**
 * Persistence contract for the fulfillment aggregate root.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Repository;

use MPCF\Domain\Fulfillment;

/**
 * Implemented in Infrastructure ({@see \MPCF\Infrastructure\Database\WpdbFulfillmentRepository}),
 * confined there per invariant I7 — nothing in Domain, Engine or
 * Application talks to storage directly.
 */
interface FulfillmentRepository {

	/**
	 * Finds a fulfillment by its own id.
	 *
	 * @param int $id Fulfillment id.
	 */
	public function find( int $id ): ?Fulfillment;

	/**
	 * Finds a fulfillment by the order it was created from — the lookup
	 * intake idempotency depends on.
	 *
	 * @param int $order_id Order id.
	 */
	public function find_by_order_id( int $order_id ): ?Fulfillment;

	/**
	 * Inserts a brand-new fulfillment and returns its assigned id.
	 *
	 * @param Fulfillment $fulfillment A fulfillment built by {@see Fulfillment::intake()}.
	 */
	public function insert( Fulfillment $fulfillment ): int;

	/**
	 * Persists a mutation to an existing fulfillment with an optimistic
	 * lock: the write is conditioned on the row's current `version` still
	 * matching what `$fulfillment` was loaded with. Returns false (and
	 * writes nothing) on a lock conflict; true, and calls
	 * `$fulfillment->increment_version()`, on success.
	 *
	 * @param Fulfillment $fulfillment Fulfillment to persist.
	 */
	public function save( Fulfillment $fulfillment ): bool;
}
