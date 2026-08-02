<?php
/**
 * Persistence contract for the fulfillment aggregate root.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Repository;

use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentQuery;
use MPCF\Domain\FulfillmentQueryResult;

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
	 * Every fulfillment created from a given order — Milestone 1's
	 * `(order_id, order_source)` uniqueness constraint means this is at most
	 * one row today, but {@see \MPCF\Woo\StatusBridge}'s outbound condition
	 * ("all fulfillments for the order are shipped") is written against the
	 * real, general architecture rather than against that constraint, so a
	 * future milestone that relaxes it (per-order splits) needs no change
	 * here.
	 *
	 * @param int $order_id Order id.
	 * @return list<Fulfillment>
	 */
	public function find_all_by_order_id( int $order_id ): array;

	/**
	 * A server-side paginated, filtered listing — the Queue's and
	 * Dashboard's only way to list fulfillments, always through indexed
	 * columns (invariant: no unindexed scan on the Queue's hot path).
	 *
	 * @param FulfillmentQuery $query Filter/sort/page.
	 */
	public function query( FulfillmentQuery $query ): FulfillmentQueryResult;

	/**
	 * Count of fulfillments whose state is one of `$states`, with no
	 * pagination overhead — the Dashboard's stat cards' only way to count.
	 *
	 * @param array<int, string> $states State keys to match.
	 */
	public function count_in_states( array $states ): int;

	/**
	 * Inserts a brand-new fulfillment and returns its assigned id, or null if
	 * the insert was rejected by the `(order_id, order_source)` uniqueness
	 * constraint — the race {@see \MPCF\Application\IntakeService} falls back
	 * to {@see find_by_order_id()} for, rather than treating as a failure.
	 *
	 * @param Fulfillment $fulfillment A fulfillment built by {@see Fulfillment::intake()}.
	 */
	public function insert( Fulfillment $fulfillment ): ?int;

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
