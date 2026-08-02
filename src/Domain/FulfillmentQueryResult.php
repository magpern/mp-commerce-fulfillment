<?php
/**
 * One page of a {@see Repository\FulfillmentRepository::query()} call.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

/**
 * Plain data (invariant I6).
 */
final class FulfillmentQueryResult {

	/**
	 * This page's rows.
	 *
	 * @var list<Fulfillment>
	 */
	private array $items;

	/**
	 * Total rows matching the query, across every page.
	 *
	 * @var int
	 */
	private int $total;

	/**
	 * 1-indexed page number.
	 *
	 * @var int
	 */
	private int $page;

	/**
	 * Rows per page.
	 *
	 * @var int
	 */
	private int $per_page;

	/**
	 * Builds a result page.
	 *
	 * @param array<int, Fulfillment> $items    This page's rows.
	 * @param int                     $total    Total rows matching the query, across every page.
	 * @param int                     $page     1-indexed page number.
	 * @param int                     $per_page Rows per page.
	 */
	public function __construct( array $items, int $total, int $page, int $per_page ) {
		$this->items    = $items;
		$this->total    = $total;
		$this->page     = $page;
		$this->per_page = $per_page;
	}

	/**
	 * This page's rows.
	 *
	 * @return list<Fulfillment>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Total rows matching the query, across every page.
	 */
	public function total(): int {
		return $this->total;
	}

	/**
	 * 1-indexed page number.
	 */
	public function page(): int {
		return $this->page;
	}

	/**
	 * Rows per page.
	 */
	public function per_page(): int {
		return $this->per_page;
	}

	/**
	 * Total number of pages, at least 1.
	 */
	public function total_pages(): int {
		return (int) max( 1, ceil( $this->total / $this->per_page ) );
	}
}
