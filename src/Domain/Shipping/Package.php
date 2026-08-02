<?php
/**
 * A physical box within a shipment.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Shipping;

use DateTimeImmutable;

/**
 * ADR-0005/D19: `Package` is first-class under `Shipment` from the start.
 * A simple shipment auto-creates its single package (seq 1), so the common
 * case still looks like one form; `+ Add package` for multi-parcel
 * consignments is additive rows on top of a schema that already supports
 * it. `label_path` is reserved for M12 and stays null until then.
 */
final class Package {

	/**
	 * Own id, or null before the repository assigns one.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * Owning shipment's id.
	 *
	 * @var int
	 */
	private int $shipment_id;

	/**
	 * 1-indexed position within the shipment.
	 *
	 * @var int
	 */
	private int $seq;

	/**
	 * Physical dimensions.
	 *
	 * @var PackageSpec
	 */
	private PackageSpec $spec;

	/**
	 * Per-package (colli) tracking number, or null.
	 *
	 * @var string|null
	 */
	private ?string $tracking_number;

	/**
	 * Reserved for M12's carrier label file — always null before then.
	 *
	 * @var string|null
	 */
	private ?string $label_path;

	/**
	 * When this package was created.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $created_at;

	/**
	 * Assembles a package. Use {@see create()} or {@see from_array()}
	 * instead of calling this directly.
	 *
	 * @param int|null          $id              Own id, or null before insert.
	 * @param int               $shipment_id     Owning shipment's id.
	 * @param int               $seq             1-indexed position within the shipment.
	 * @param PackageSpec       $spec            Physical dimensions.
	 * @param string|null       $tracking_number Per-package tracking number, or null.
	 * @param string|null       $label_path      Reserved for M12, or null.
	 * @param DateTimeImmutable $created_at      When created.
	 */
	private function __construct(
		?int $id,
		int $shipment_id,
		int $seq,
		PackageSpec $spec,
		?string $tracking_number,
		?string $label_path,
		DateTimeImmutable $created_at
	) {
		$this->id              = $id;
		$this->shipment_id     = $shipment_id;
		$this->seq             = $seq;
		$this->spec            = $spec;
		$this->tracking_number = $tracking_number;
		$this->label_path      = $label_path;
		$this->created_at      = $created_at;
	}

	/**
	 * Creates a brand-new package with no spec recorded yet.
	 *
	 * @param int               $shipment_id Owning shipment's id.
	 * @param int               $seq         1-indexed position within the shipment.
	 * @param DateTimeImmutable $now         Current time.
	 */
	public static function create( int $shipment_id, int $seq, DateTimeImmutable $now ): self {
		return new self( null, $shipment_id, $seq, PackageSpec::none(), null, null, $now );
	}

	/**
	 * Rebuilds a package from its array shape.
	 *
	 * @param array<string, mixed> $data Array shape produced by {@see to_array()}.
	 */
	public static function from_array( array $data ): self {
		return new self(
			isset( $data['id'] ) ? (int) $data['id'] : null,
			(int) $data['shipment_id'],
			(int) $data['seq'],
			PackageSpec::create(
				isset( $data['weight_grams'] ) ? (int) $data['weight_grams'] : null,
				isset( $data['length_mm'] ) ? (int) $data['length_mm'] : null,
				isset( $data['width_mm'] ) ? (int) $data['width_mm'] : null,
				isset( $data['height_mm'] ) ? (int) $data['height_mm'] : null
			),
			isset( $data['tracking_number'] ) ? (string) $data['tracking_number'] : null,
			isset( $data['label_path'] ) ? (string) $data['label_path'] : null,
			$data['created_at']
		);
	}

	/**
	 * The array shape {@see from_array()} rebuilds from.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'shipment_id'     => $this->shipment_id,
			'seq'             => $this->seq,
			'weight_grams'    => $this->spec->weight_grams(),
			'length_mm'       => $this->spec->length_mm(),
			'width_mm'        => $this->spec->width_mm(),
			'height_mm'       => $this->spec->height_mm(),
			'tracking_number' => $this->tracking_number,
			'label_path'      => $this->label_path,
			'created_at'      => $this->created_at,
		);
	}

	/**
	 * Own id, or null before the repository assigns one.
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * Owning shipment's id.
	 */
	public function shipment_id(): int {
		return $this->shipment_id;
	}

	/**
	 * 1-indexed position within the shipment.
	 */
	public function seq(): int {
		return $this->seq;
	}

	/**
	 * Physical dimensions.
	 */
	public function spec(): PackageSpec {
		return $this->spec;
	}

	/**
	 * Per-package (colli) tracking number, or null.
	 */
	public function tracking_number(): ?string {
		return $this->tracking_number;
	}

	/**
	 * Reserved for M12's carrier label file — always null before then.
	 */
	public function label_path(): ?string {
		return $this->label_path;
	}

	/**
	 * When this package was created.
	 */
	public function created_at(): DateTimeImmutable {
		return $this->created_at;
	}

	/**
	 * Records this package's physical dimensions.
	 *
	 * @param PackageSpec $spec New spec.
	 */
	public function set_spec( PackageSpec $spec ): void {
		$this->spec = $spec;
	}

	/**
	 * Records this package's colli tracking number.
	 *
	 * @param string|null $tracking_number New tracking number, or null to clear it.
	 */
	public function set_tracking_number( ?string $tracking_number ): void {
		$this->tracking_number = ( null !== $tracking_number && '' !== $tracking_number ) ? $tracking_number : null;
	}
}
