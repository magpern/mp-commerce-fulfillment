<?php
/**
 * An order read from its owning platform, at the moment it was read.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

/**
 * Immutable value object — what an {@see OrderSource} hands back.
 * Deliberately minimal: exactly the fields {@see Fulfillment::intake()}
 * needs to snapshot, nothing that would tempt a caller into treating this
 * as a general-purpose order read model. The order platform stays the
 * source of truth for everything else, for the lifetime of the order —
 * this is a snapshot at intake time, not a live reference.
 */
final class OrderSnapshot {

	/**
	 * Order id.
	 *
	 * @var int
	 */
	private int $order_id;

	/**
	 * Identifies which platform this order belongs to.
	 *
	 * @var string
	 */
	private string $order_source;

	/**
	 * Order number, as the platform displays it.
	 *
	 * @var string
	 */
	private string $order_number;

	/**
	 * Customer display name, at snapshot time.
	 *
	 * @var string
	 */
	private string $customer_name;

	/**
	 * Order status, at snapshot time.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Line items.
	 *
	 * @var array<int, OrderLineSnapshot>
	 */
	private array $items;

	/**
	 * Assembles a snapshot. Use {@see create()} instead of calling this
	 * directly.
	 *
	 * @param int                           $order_id      Order id.
	 * @param string                        $order_source  Owning platform identifier.
	 * @param string                        $order_number  Order number.
	 * @param string                        $customer_name Customer display name.
	 * @param string                        $status        Order status.
	 * @param array<int, OrderLineSnapshot> $items         Line items.
	 */
	private function __construct( int $order_id, string $order_source, string $order_number, string $customer_name, string $status, array $items ) {
		$this->order_id      = $order_id;
		$this->order_source  = $order_source;
		$this->order_number  = $order_number;
		$this->customer_name = $customer_name;
		$this->status        = $status;
		$this->items         = $items;
	}

	/**
	 * Builds an order snapshot.
	 *
	 * @param int                           $order_id      Order id.
	 * @param string                        $order_source  Owning platform identifier.
	 * @param string                        $order_number  Order number.
	 * @param string                        $customer_name Customer display name.
	 * @param string                        $status        Order status.
	 * @param array<int, OrderLineSnapshot> $items         Line items.
	 */
	public static function create( int $order_id, string $order_source, string $order_number, string $customer_name, string $status, array $items ): self {
		return new self( $order_id, $order_source, $order_number, $customer_name, $status, $items );
	}

	/**
	 * Order id.
	 */
	public function order_id(): int {
		return $this->order_id;
	}

	/**
	 * Identifies which platform this order belongs to.
	 */
	public function order_source(): string {
		return $this->order_source;
	}

	/**
	 * Order number, as the platform displays it.
	 */
	public function order_number(): string {
		return $this->order_number;
	}

	/**
	 * Customer display name, at snapshot time.
	 */
	public function customer_name(): string {
		return $this->customer_name;
	}

	/**
	 * Order status, at snapshot time.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Line items.
	 *
	 * @return array<int, OrderLineSnapshot>
	 */
	public function items(): array {
		return $this->items;
	}
}
