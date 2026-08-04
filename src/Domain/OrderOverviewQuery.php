<?php
/**
 * Filter/page inputs for the Orders operational overview.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

/**
 * Preset filters map to either order statuses or fulfillment states;
 * {@see \MPCF\Application\OrderOverviewService} chooses the listing strategy.
 */
final class OrderOverviewQuery {

	/**
	 * All recent orders.
	 */
	public const FILTER_ALL = 'all';

	/**
	 * Exception fulfillment states.
	 */
	public const FILTER_NEEDS_ATTENTION = 'needs_attention';

	/**
	 * Active warehouse workflow states.
	 */
	public const FILTER_WAREHOUSE_ACTIVE = 'warehouse_active';

	/**
	 * Pending payment / on-hold Woo statuses.
	 */
	public const FILTER_AWAITING_PAYMENT = 'awaiting_payment';

	/**
	 * Completed Woo statuses.
	 */
	public const FILTER_COMPLETED = 'completed';

	/**
	 * Cancelled / refunded Woo statuses.
	 */
	public const FILTER_CANCELLED = 'cancelled';

	/**
	 * Active filter preset.
	 *
	 * @var string
	 */
	private string $filter;

	/**
	 * Free-text search term.
	 *
	 * @var string
	 */
	private string $search;

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
	 * Builds a query.
	 *
	 * @param string $filter   One of the FILTER_* constants.
	 * @param string $search   Free-text search term.
	 * @param int    $page     1-indexed page.
	 * @param int    $per_page Rows per page.
	 */
	public function __construct( string $filter = self::FILTER_ALL, string $search = '', int $page = 1, int $per_page = 20 ) {
		$this->filter   = self::normalize_filter( $filter );
		$this->search   = trim( $search );
		$this->page     = max( 1, $page );
		$this->per_page = max( 1, min( 100, $per_page ) );
	}

	/**
	 * Active filter preset.
	 */
	public function filter(): string {
		return $this->filter;
	}

	/**
	 * Free-text search term.
	 */
	public function search(): string {
		return $this->search;
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
	 * Whether this filter is driven from fulfillment state rather than WC status.
	 */
	public function is_fulfillment_led(): bool {
		return in_array(
			$this->filter,
			array( self::FILTER_NEEDS_ATTENTION, self::FILTER_WAREHOUSE_ACTIVE ),
			true
		);
	}

	/**
	 * Normalizes an unknown filter key to {@see FILTER_ALL}.
	 *
	 * @param string $filter Raw filter key.
	 */
	private static function normalize_filter( string $filter ): string {
		$allowed = array(
			self::FILTER_ALL,
			self::FILTER_NEEDS_ATTENTION,
			self::FILTER_WAREHOUSE_ACTIVE,
			self::FILTER_AWAITING_PAYMENT,
			self::FILTER_COMPLETED,
			self::FILTER_CANCELLED,
		);

		return in_array( $filter, $allowed, true ) ? $filter : self::FILTER_ALL;
	}
}
