<?php
/**
 * A line-quantity allocation of a fulfillment item into a package.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Shipping;

/**
 * Milestone 2 always allocates every packed line to package 1 (PO
 * decision, Architecture Plan §IV.0.2) — `PackingService` is the only
 * writer of these rows in this milestone. The shape already supports M4's
 * line-allocation split (an item spread across more than one package)
 * with no schema change.
 */
final class PackageItem {

	/**
	 * Own id, or null before the repository assigns one.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * Owning package's id.
	 *
	 * @var int
	 */
	private int $package_id;

	/**
	 * The fulfillment item this allocation is for.
	 *
	 * @var int
	 */
	private int $fulfillment_item_id;

	/**
	 * Quantity allocated to this package.
	 *
	 * @var int
	 */
	private int $qty;

	/**
	 * Assembles an allocation. Use {@see create()} or {@see from_array()}
	 * instead of calling this directly.
	 *
	 * @param int|null $id                  Own id, or null before insert.
	 * @param int      $package_id          Owning package's id.
	 * @param int      $fulfillment_item_id The fulfillment item this allocation is for.
	 * @param int      $qty                 Quantity allocated.
	 */
	private function __construct( ?int $id, int $package_id, int $fulfillment_item_id, int $qty ) {
		$this->id                  = $id;
		$this->package_id          = $package_id;
		$this->fulfillment_item_id = $fulfillment_item_id;
		$this->qty                 = $qty;
	}

	/**
	 * Creates a brand-new allocation.
	 *
	 * @param int $package_id          Owning package's id.
	 * @param int $fulfillment_item_id The fulfillment item this allocation is for.
	 * @param int $qty                 Quantity allocated.
	 */
	public static function create( int $package_id, int $fulfillment_item_id, int $qty ): self {
		return new self( null, $package_id, $fulfillment_item_id, max( 0, $qty ) );
	}

	/**
	 * Rebuilds an allocation from its array shape.
	 *
	 * @param array{id?:int|null,package_id:int,fulfillment_item_id:int,qty:int} $data Array shape produced by {@see to_array()}.
	 */
	public static function from_array( array $data ): self {
		return new self(
			isset( $data['id'] ) ? (int) $data['id'] : null,
			(int) $data['package_id'],
			(int) $data['fulfillment_item_id'],
			(int) $data['qty']
		);
	}

	/**
	 * The array shape {@see from_array()} rebuilds from.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                  => $this->id,
			'package_id'          => $this->package_id,
			'fulfillment_item_id' => $this->fulfillment_item_id,
			'qty'                 => $this->qty,
		);
	}

	/**
	 * Own id, or null before the repository assigns one.
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * Owning package's id.
	 */
	public function package_id(): int {
		return $this->package_id;
	}

	/**
	 * The fulfillment item this allocation is for.
	 */
	public function fulfillment_item_id(): int {
		return $this->fulfillment_item_id;
	}

	/**
	 * Quantity allocated to this package.
	 */
	public function qty(): int {
		return $this->qty;
	}
}
