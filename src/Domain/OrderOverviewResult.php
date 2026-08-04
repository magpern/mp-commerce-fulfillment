<?php
/**
 * Paginated Orders overview result.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

/**
 * Plain data returned by {@see \MPCF\Application\OrderOverviewService::list()}.
 */
final class OrderOverviewResult {

	/**
	 * This page's rows.
	 *
	 * @var list<OrderOverviewRow>
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
	 * @param array<int, OrderOverviewRow> $items    Page rows.
	 * @param int                          $total    Total matching rows.
	 * @param int                          $page     1-indexed page.
	 * @param int                          $per_page Page size.
	 */
	public function __construct( array $items, int $total, int $page, int $per_page ) {
		$this->items    = array_values( $items );
		$this->total    = max( 0, $total );
		$this->page     = max( 1, $page );
		$this->per_page = max( 1, $per_page );
	}

	/**
	 * This page's rows.
	 *
	 * @return list<OrderOverviewRow>
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
		return (int) max( 1, (int) ceil( $this->total / $this->per_page ) );
	}
}
