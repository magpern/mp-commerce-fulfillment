<?php
/**
 * A server-side, paginated, indexed-only Queue listing filter.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

/**
 * Plain data (invariant I6): everything the Queue's filter bar, the
 * Dashboard's next-actions band, and the CLI could ever ask
 * {@see Repository\FulfillmentRepository::query()} for, expressed as
 * indexed-column conditions only — there is no free-text field here on
 * purpose; free text is resolved to a `fulfillment_ids` filter by
 * {@see SearchQuery} before this object is built, so this class never
 * needs to know how a search term was classified.
 */
final class FulfillmentQuery {

	/**
	 * Sentinel {@see $assignee} value matching only unassigned fulfillments.
	 */
	public const SENTINEL_UNASSIGNED = 'unassigned';

	/**
	 * State keys to match, or an empty list for "any state".
	 *
	 * @var list<string>
	 */
	private array $states;

	/**
	 * Assignee user id to match, {@see SENTINEL_UNASSIGNED} to match only
	 * unassigned fulfillments, or null for "any assignee".
	 *
	 * @var int|string|null
	 */
	private $assignee;

	/**
	 * Fulfillment ids to restrict the result to (from a resolved search
	 * term), or null for "no restriction".
	 *
	 * @var list<int>|null
	 */
	private ?array $fulfillment_ids;

	/**
	 * Only fulfillments whose `state_entered_at` is at or before this many
	 * seconds ago ("age" filter), or null for "any age".
	 *
	 * @var int|null
	 */
	private ?int $min_age_seconds;

	/**
	 * Column to sort by.
	 *
	 * @var string
	 */
	private string $order_by;

	/**
	 * Sort direction, `ASC` or `DESC`.
	 *
	 * @var string
	 */
	private string $order;

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
	 * Builds a query. All filters default to "no restriction".
	 *
	 * @param array<int, string>   $states          State keys to match.
	 * @param int|string|null      $assignee        Assignee user id, {@see SENTINEL_UNASSIGNED}, or null.
	 * @param array<int, int>|null $fulfillment_ids Fulfillment ids to restrict to.
	 * @param int|null             $min_age_seconds Minimum age in the current state, in seconds.
	 * @param string               $order_by        Column to sort by.
	 * @param string               $order           `ASC` or `DESC`.
	 * @param int                  $page            1-indexed page number.
	 * @param int                  $per_page        Rows per page.
	 */
	public function __construct(
		array $states = array(),
		$assignee = null,
		?array $fulfillment_ids = null,
		?int $min_age_seconds = null,
		string $order_by = 'created_at',
		string $order = 'DESC',
		int $page = 1,
		int $per_page = 20
	) {
		$this->states          = $states;
		$this->assignee        = $assignee;
		$this->fulfillment_ids = $fulfillment_ids;
		$this->min_age_seconds = $min_age_seconds;
		$this->order_by        = $order_by;
		$this->order           = $order;
		$this->page            = max( 1, $page );
		$this->per_page        = max( 1, $per_page );
	}

	/**
	 * State keys to match, or an empty list for "any state".
	 *
	 * @return list<string>
	 */
	public function states(): array {
		return $this->states;
	}

	/**
	 * Assignee user id, {@see SENTINEL_UNASSIGNED}, or null.
	 *
	 * @return int|string|null
	 */
	public function assignee() {
		return $this->assignee;
	}

	/**
	 * Fulfillment ids to restrict the result to, or null for none.
	 *
	 * @return list<int>|null
	 */
	public function fulfillment_ids(): ?array {
		return $this->fulfillment_ids;
	}

	/**
	 * Minimum age in the current state, in seconds, or null for any age.
	 */
	public function min_age_seconds(): ?int {
		return $this->min_age_seconds;
	}

	/**
	 * Column to sort by.
	 */
	public function order_by(): string {
		return $this->order_by;
	}

	/**
	 * Sort direction, `ASC` or `DESC`.
	 */
	public function order(): string {
		return $this->order;
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
	 * Row offset for the current page.
	 */
	public function offset(): int {
		return ( $this->page - 1 ) * $this->per_page;
	}
}
