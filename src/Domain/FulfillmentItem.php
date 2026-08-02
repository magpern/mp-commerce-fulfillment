<?php
/**
 * One line item within a fulfillment.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

/**
 * Snapshotted (`sku_snapshot`, `name_snapshot`) so picking lists and audit
 * history stay stable even if the underlying product is later renamed or
 * deleted — the snapshot is a display hint, never authority; the platform's
 * own product records remain the source of truth for anything beyond
 * display.
 */
final class FulfillmentItem {

	/**
	 * Own id, or null before the repository assigns one.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * Owning fulfillment's id.
	 *
	 * @var int
	 */
	private int $fulfillment_id;

	/**
	 * The order's line item id this was ingested from.
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
	 * SKU at intake time.
	 *
	 * @var string
	 */
	private string $sku_snapshot;

	/**
	 * Product name at intake time.
	 *
	 * @var string
	 */
	private string $name_snapshot;

	/**
	 * Quantity ordered.
	 *
	 * @var int
	 */
	private int $qty_ordered;

	/**
	 * Quantity picked so far.
	 *
	 * @var int
	 */
	private int $qty_picked;

	/**
	 * Quantity packed so far.
	 *
	 * @var int
	 */
	private int $qty_packed;

	/**
	 * Bin/shelf location, if known. Reserved for the future
	 * multi-warehouse/location milestone.
	 *
	 * @var string|null
	 */
	private ?string $location_snapshot;

	/**
	 * Assembles an item. Use {@see intake()} instead of calling this directly.
	 *
	 * @param int|null    $id                Own id, or null before insert.
	 * @param int         $fulfillment_id     Owning fulfillment's id.
	 * @param int         $order_item_id      Origin order line item id.
	 * @param int         $product_id         Product id.
	 * @param int         $variation_id       Variation id, or 0.
	 * @param string      $sku_snapshot       SKU at intake time.
	 * @param string      $name_snapshot      Product name at intake time.
	 * @param int         $qty_ordered        Quantity ordered.
	 * @param int         $qty_picked         Quantity picked so far.
	 * @param int         $qty_packed         Quantity packed so far.
	 * @param string|null $location_snapshot  Bin/shelf location, if known.
	 */
	private function __construct(
		?int $id,
		int $fulfillment_id,
		int $order_item_id,
		int $product_id,
		int $variation_id,
		string $sku_snapshot,
		string $name_snapshot,
		int $qty_ordered,
		int $qty_picked,
		int $qty_packed,
		?string $location_snapshot
	) {
		$this->id                = $id;
		$this->fulfillment_id    = $fulfillment_id;
		$this->order_item_id     = $order_item_id;
		$this->product_id        = $product_id;
		$this->variation_id      = $variation_id;
		$this->sku_snapshot      = $sku_snapshot;
		$this->name_snapshot     = $name_snapshot;
		$this->qty_ordered       = $qty_ordered;
		$this->qty_picked        = $qty_picked;
		$this->qty_packed        = $qty_packed;
		$this->location_snapshot = $location_snapshot;
	}

	/**
	 * Builds a new line item at intake time — nothing picked or packed yet.
	 *
	 * @param int    $fulfillment_id Owning fulfillment's id.
	 * @param int    $order_item_id  Origin order line item id.
	 * @param int    $product_id     Product id.
	 * @param int    $variation_id   Variation id, or 0.
	 * @param string $sku_snapshot   SKU at intake time.
	 * @param string $name_snapshot  Product name at intake time.
	 * @param int    $qty_ordered    Quantity ordered.
	 */
	public static function intake(
		int $fulfillment_id,
		int $order_item_id,
		int $product_id,
		int $variation_id,
		string $sku_snapshot,
		string $name_snapshot,
		int $qty_ordered
	): self {
		return new self( null, $fulfillment_id, $order_item_id, $product_id, $variation_id, $sku_snapshot, $name_snapshot, $qty_ordered, 0, 0, null );
	}

	/**
	 * Rebuilds an item from its array shape.
	 *
	 * @param array{id?:int|null,fulfillment_id:int,order_item_id:int,product_id:int,variation_id:int,sku_snapshot:string,name_snapshot:string,qty_ordered:int,qty_picked:int,qty_packed:int,location_snapshot?:string|null} $data Array shape produced by {@see to_array()}.
	 */
	public static function from_array( array $data ): self {
		return new self(
			isset( $data['id'] ) ? (int) $data['id'] : null,
			(int) $data['fulfillment_id'],
			(int) $data['order_item_id'],
			(int) $data['product_id'],
			(int) $data['variation_id'],
			(string) $data['sku_snapshot'],
			(string) $data['name_snapshot'],
			(int) $data['qty_ordered'],
			(int) $data['qty_picked'],
			(int) $data['qty_packed'],
			isset( $data['location_snapshot'] ) ? (string) $data['location_snapshot'] : null
		);
	}

	/**
	 * The array shape {@see from_array()} rebuilds from.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                => $this->id,
			'fulfillment_id'    => $this->fulfillment_id,
			'order_item_id'     => $this->order_item_id,
			'product_id'        => $this->product_id,
			'variation_id'      => $this->variation_id,
			'sku_snapshot'      => $this->sku_snapshot,
			'name_snapshot'     => $this->name_snapshot,
			'qty_ordered'       => $this->qty_ordered,
			'qty_picked'        => $this->qty_picked,
			'qty_packed'        => $this->qty_packed,
			'location_snapshot' => $this->location_snapshot,
		);
	}

	/**
	 * Own id, or null before the repository assigns one.
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * Owning fulfillment's id.
	 */
	public function fulfillment_id(): int {
		return $this->fulfillment_id;
	}

	/**
	 * The order's line item id this was ingested from.
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
	 * SKU at intake time.
	 */
	public function sku_snapshot(): string {
		return $this->sku_snapshot;
	}

	/**
	 * Product name at intake time.
	 */
	public function name_snapshot(): string {
		return $this->name_snapshot;
	}

	/**
	 * Quantity ordered.
	 */
	public function qty_ordered(): int {
		return $this->qty_ordered;
	}

	/**
	 * Quantity picked so far.
	 */
	public function qty_picked(): int {
		return $this->qty_picked;
	}

	/**
	 * Quantity packed so far.
	 */
	public function qty_packed(): int {
		return $this->qty_packed;
	}

	/**
	 * Bin/shelf location, if known.
	 */
	public function location_snapshot(): ?string {
		return $this->location_snapshot;
	}

	/**
	 * Records the quantity picked so far, clamped to `0..qty_ordered`
	 * (Architecture Plan §IV.5.2) — a picked/packed quantity can never
	 * exceed what was ordered, regardless of what a caller supplies.
	 *
	 * @param int $qty New picked quantity.
	 */
	public function record_picked( int $qty ): void {
		$this->qty_picked = max( 0, min( $qty, $this->qty_ordered ) );
	}

	/**
	 * Records the quantity packed so far, clamped to `0..qty_ordered`.
	 *
	 * @param int $qty New packed quantity.
	 */
	public function record_packed( int $qty ): void {
		$this->qty_packed = max( 0, min( $qty, $this->qty_ordered ) );
	}

	/**
	 * Whether every ordered unit has been picked.
	 */
	public function is_fully_picked(): bool {
		return $this->qty_picked >= $this->qty_ordered;
	}

	/**
	 * Whether every ordered unit has been packed.
	 */
	public function is_fully_packed(): bool {
		return $this->qty_packed >= $this->qty_ordered;
	}
}
