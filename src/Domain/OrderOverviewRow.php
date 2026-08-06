<?php
/**
 * One Orders-screen row: Woo order + optional fulfillment association.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

use DateTimeImmutable;

/**
 * Read-only presentation row. Never creates or mutates fulfillments.
 */
final class OrderOverviewRow {

	/**
	 * Order id.
	 *
	 * @var int
	 */
	private int $order_id;

	/**
	 * Display order number.
	 *
	 * @var string
	 */
	private string $order_number;

	/**
	 * Customer display name.
	 *
	 * @var string
	 */
	private string $customer_name;

	/**
	 * Order date.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $order_date;

	/**
	 * Order status key without `wc-`.
	 *
	 * @var string
	 */
	private string $woo_status;

	/**
	 * Fulfillment state key, or null when no fulfillment exists.
	 *
	 * @var string|null
	 */
	private ?string $fulfillment_state;

	/**
	 * Fulfillment id, or null when none exists.
	 *
	 * @var int|null
	 */
	private ?int $fulfillment_id;

	/**
	 * Assignee user id, or null.
	 *
	 * @var int|null
	 */
	private ?int $assignee_id;

	/**
	 * Operator-facing operational state label.
	 *
	 * @var string
	 */
	private string $operational_state;

	/**
	 * Operator-facing next-action label.
	 *
	 * @var string
	 */
	private string $next_action;

	/**
	 * `workspace`, `woocommerce`, or `none`.
	 *
	 * @var string
	 */
	private string $open_target;

	/**
	 * Builds a row.
	 *
	 * @param int               $order_id          Order id.
	 * @param string            $order_number      Display order number.
	 * @param string            $customer_name     Customer name.
	 * @param DateTimeImmutable $order_date        Order date.
	 * @param string            $woo_status        WC status key.
	 * @param string|null       $fulfillment_state Fulfillment state or null.
	 * @param int|null          $fulfillment_id    Fulfillment id or null.
	 * @param int|null          $assignee_id       Assignee user id or null.
	 * @param string            $operational_state Operational state label.
	 * @param string            $next_action       Next-action label.
	 * @param string            $open_target       Open destination token.
	 */
	public function __construct(
		int $order_id,
		string $order_number,
		string $customer_name,
		DateTimeImmutable $order_date,
		string $woo_status,
		?string $fulfillment_state,
		?int $fulfillment_id,
		?int $assignee_id,
		string $operational_state,
		string $next_action,
		string $open_target
	) {
		$this->order_id          = $order_id;
		$this->order_number      = $order_number;
		$this->customer_name     = $customer_name;
		$this->order_date        = $order_date;
		$this->woo_status        = $woo_status;
		$this->fulfillment_state = $fulfillment_state;
		$this->fulfillment_id    = $fulfillment_id;
		$this->assignee_id       = $assignee_id;
		$this->operational_state = $operational_state;
		$this->next_action       = $next_action;
		$this->open_target       = $open_target;
	}

	/**
	 * Order id.
	 */
	public function order_id(): int {
		return $this->order_id;
	}

	/**
	 * Display order number.
	 */
	public function order_number(): string {
		return $this->order_number;
	}

	/**
	 * Customer display name.
	 */
	public function customer_name(): string {
		return $this->customer_name;
	}

	/**
	 * Order date.
	 */
	public function order_date(): DateTimeImmutable {
		return $this->order_date;
	}

	/**
	 * Order status key without `wc-`.
	 */
	public function woo_status(): string {
		return $this->woo_status;
	}

	/**
	 * Fulfillment state key, or null when no fulfillment exists.
	 */
	public function fulfillment_state(): ?string {
		return $this->fulfillment_state;
	}

	/**
	 * Fulfillment id, or null when none exists.
	 */
	public function fulfillment_id(): ?int {
		return $this->fulfillment_id;
	}

	/**
	 * Assignee user id, or null.
	 */
	public function assignee_id(): ?int {
		return $this->assignee_id;
	}

	/**
	 * Whether a fulfillment is associated.
	 */
	public function has_fulfillment(): bool {
		return null !== $this->fulfillment_id;
	}

	/**
	 * Operator-facing operational state label.
	 */
	public function operational_state(): string {
		return $this->operational_state;
	}

	/**
	 * Operator-facing next-action label.
	 */
	public function next_action(): string {
		return $this->next_action;
	}

	/**
	 * Open destination token.
	 */
	public function open_target(): string {
		return $this->open_target;
	}
}
