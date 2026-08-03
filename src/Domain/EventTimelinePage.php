<?php
/**
 * One page of a {@see Repository\EventRepository::timeline_page_for_fulfillment()} call.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

/**
 * Plain data (invariant I6). Mirrors {@see FulfillmentQueryResult}'s shape
 * so the Detail admin screen's timeline pager reuses the same
 * page/per_page/total/total_pages vocabulary the Queue's pagination
 * already established, rather than inventing a second one.
 */
final class EventTimelinePage {

	/**
	 * This page's rows, oldest first.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $items;

	/**
	 * Total rows for the fulfillment, across every page.
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
	 * @param list<array<string, mixed>> $items    This page's rows, oldest first.
	 * @param int                        $total    Total rows for the fulfillment, across every page.
	 * @param int                        $page     1-indexed page number.
	 * @param int                        $per_page Rows per page.
	 */
	public function __construct( array $items, int $total, int $page, int $per_page ) {
		$this->items    = $items;
		$this->total    = $total;
		$this->page     = $page;
		$this->per_page = $per_page;
	}

	/**
	 * This page's rows, oldest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Total rows for the fulfillment, across every page.
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
