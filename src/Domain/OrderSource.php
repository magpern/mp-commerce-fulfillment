<?php
/**
 * Port to the platform that owns orders.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

/**
 * No non-platform order sources exist in Milestone 1 — this plugin has
 * exactly one implementation, {@see \MPCF\Woo\WooOrderSource} — but the
 * port exists from this milestone so that assumption is architectural, not
 * structural: nothing above this interface ever names the order platform
 * directly (invariant I8 confines that to `src/Woo/`).
 */
interface OrderSource {

	/**
	 * Reads an order by id.
	 *
	 * @param int $order_id Order id.
	 */
	public function find( int $order_id ): ?OrderSnapshot;

	/**
	 * Every order id currently in a given status — the backfill CLI command's
	 * only way to discover what to ingest, so it never needs to name a
	 * platform query symbol itself (invariant I8).
	 *
	 * @param string $status Status to match.
	 * @return list<int>
	 */
	public function find_ids_by_status( string $status ): array;

	/**
	 * Paginated lightweight order rows for the Orders overview.
	 *
	 * @param array<int, string> $statuses Status keys without `wc-`; empty = any non-trash status.
	 * @param int                $page     1-indexed page.
	 * @param int                $per_page Rows per page.
	 * @param string             $search   Optional free-text search (order # / customer).
	 */
	public function list_summaries( array $statuses, int $page, int $per_page, string $search = '' ): OperationalOrderListResult;

	/**
	 * Lightweight order rows for the given ids, preserving input order.
	 * Missing ids are omitted.
	 *
	 * @param array<int, int> $order_ids Order ids.
	 * @return list<OperationalOrderSummary>
	 */
	public function summaries_by_ids( array $order_ids ): array;
}
