<?php
/**
 * One line item read from an order, at the moment it was read.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

/**
 * Immutable value object. Feeds {@see FulfillmentItem::intake()} — this is
 * the shape an {@see OrderSource} hands back, deliberately independent of
 * any specific order platform's own line-item representation.
 */
final class OrderLineSnapshot {

	/**
	 * The order's own line item id.
	 *
	 * @var int
	 */
	private int $order_item_id;

	/**
	 * Product id.
	 *
	 * @var int
	 */
	private int $product_id;

	/**
	 * Variation id, or 0 for a simple product.
	 *
	 * @var int
	 */
	private int $variation_id;

	/**
	 * SKU at the moment this snapshot was taken.
	 *
	 * @var string
	 */
	private string $sku;

	/**
	 * Product name at the moment this snapshot was taken.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Quantity ordered.
	 *
	 * @var int
	 */
	private int $quantity;

	/**
	 * Assembles a snapshot. Use {@see create()} instead of calling this
	 * directly.
	 *
	 * @param int    $order_item_id The order's own line item id.
	 * @param int    $product_id    Product id.
	 * @param int    $variation_id  Variation id, or 0.
	 * @param string $sku           SKU at snapshot time.
	 * @param string $name          Product name at snapshot time.
	 * @param int    $quantity      Quantity ordered.
	 */
	private function __construct( int $order_item_id, int $product_id, int $variation_id, string $sku, string $name, int $quantity ) {
		$this->order_item_id = $order_item_id;
		$this->product_id    = $product_id;
		$this->variation_id  = $variation_id;
		$this->sku           = $sku;
		$this->name          = $name;
		$this->quantity      = $quantity;
	}

	/**
	 * Builds a line snapshot.
	 *
	 * @param int    $order_item_id The order's own line item id.
	 * @param int    $product_id    Product id.
	 * @param int    $variation_id  Variation id, or 0.
	 * @param string $sku           SKU at snapshot time.
	 * @param string $name          Product name at snapshot time.
	 * @param int    $quantity      Quantity ordered.
	 */
	public static function create( int $order_item_id, int $product_id, int $variation_id, string $sku, string $name, int $quantity ): self {
		return new self( $order_item_id, $product_id, $variation_id, $sku, $name, $quantity );
	}

	/**
	 * The order's own line item id.
	 */
	public function order_item_id(): int {
		return $this->order_item_id;
	}

	/**
	 * Product id.
	 */
	public function product_id(): int {
		return $this->product_id;
	}

	/**
	 * Variation id, or 0 for a simple product.
	 */
	public function variation_id(): int {
		return $this->variation_id;
	}

	/**
	 * SKU at the moment this snapshot was taken.
	 */
	public function sku(): string {
		return $this->sku;
	}

	/**
	 * Product name at the moment this snapshot was taken.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Quantity ordered.
	 */
	public function quantity(): int {
		return $this->quantity;
	}
}
