<?php
/**
 * A lightweight store order row for the Orders overview.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

use DateTimeImmutable;

/**
 * Immutable list-row shape returned by {@see OrderSource::list_summaries()}
 * and {@see OrderSource::summaries_by_ids()}. Deliberately thinner than
 * {@see OrderSnapshot}: no line items or ship-to lines, so the Orders
 * screen can page Woo orders without N+1 product hydration.
 */
final class OperationalOrderSummary {

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
	 * order status key without the `wc-` prefix.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Order created date.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $created_at;

	/**
	 * Assembles a summary. Use {@see create()} instead of calling this directly.
	 *
	 * @param int               $order_id      Order id.
	 * @param string            $order_number  Display order number.
	 * @param string            $customer_name Customer display name.
	 * @param string            $status        WC status key.
	 * @param DateTimeImmutable $created_at    Order created date.
	 */
	private function __construct( int $order_id, string $order_number, string $customer_name, string $status, DateTimeImmutable $created_at ) {
		$this->order_id      = $order_id;
		$this->order_number  = $order_number;
		$this->customer_name = $customer_name;
		$this->status        = $status;
		$this->created_at    = $created_at;
	}

	/**
	 * Builds an operational order summary.
	 *
	 * @param int               $order_id      Order id.
	 * @param string            $order_number  Display order number.
	 * @param string            $customer_name Customer display name.
	 * @param string            $status        WC status key.
	 * @param DateTimeImmutable $created_at    Order created date.
	 */
	public static function create( int $order_id, string $order_number, string $customer_name, string $status, DateTimeImmutable $created_at ): self {
		return new self( $order_id, $order_number, $customer_name, $status, $created_at );
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
	 * order status key without the `wc-` prefix.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Order created date.
	 */
	public function created_at(): DateTimeImmutable {
		return $this->created_at;
	}
}
