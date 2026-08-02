<?php
/**
 * A package's physical dimensions.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Shipping;

/**
 * Immutable value object. Weight in grams, dimensions in millimetres,
 * integers only (D15) — no float drift, display conversion is a UI
 * concern. `is_present()` is the real data source for the
 * `package_spec_present` guard (Architecture Plan §IV.3.B): a spec counts
 * as present once a weight has been recorded, dimensions are optional.
 */
final class PackageSpec {

	/**
	 * Weight in grams, or null if not yet recorded.
	 *
	 * @var int|null
	 */
	private ?int $weight_grams;

	/**
	 * Length in millimetres, or null.
	 *
	 * @var int|null
	 */
	private ?int $length_mm;

	/**
	 * Width in millimetres, or null.
	 *
	 * @var int|null
	 */
	private ?int $width_mm;

	/**
	 * Height in millimetres, or null.
	 *
	 * @var int|null
	 */
	private ?int $height_mm;

	/**
	 * Assembles a spec. Use {@see create()} or {@see none()} instead of
	 * calling this directly.
	 *
	 * @param int|null $weight_grams Weight in grams, or null.
	 * @param int|null $length_mm    Length in millimetres, or null.
	 * @param int|null $width_mm     Width in millimetres, or null.
	 * @param int|null $height_mm    Height in millimetres, or null.
	 */
	private function __construct( ?int $weight_grams, ?int $length_mm, ?int $width_mm, ?int $height_mm ) {
		$this->weight_grams = null !== $weight_grams ? max( 0, $weight_grams ) : null;
		$this->length_mm    = null !== $length_mm ? max( 0, $length_mm ) : null;
		$this->width_mm     = null !== $width_mm ? max( 0, $width_mm ) : null;
		$this->height_mm    = null !== $height_mm ? max( 0, $height_mm ) : null;
	}

	/**
	 * Builds a spec.
	 *
	 * @param int|null $weight_grams Weight in grams, or null.
	 * @param int|null $length_mm    Length in millimetres, or null.
	 * @param int|null $width_mm     Width in millimetres, or null.
	 * @param int|null $height_mm    Height in millimetres, or null.
	 */
	public static function create( ?int $weight_grams, ?int $length_mm = null, ?int $width_mm = null, ?int $height_mm = null ): self {
		return new self( $weight_grams, $length_mm, $width_mm, $height_mm );
	}

	/**
	 * An empty spec — nothing recorded yet.
	 */
	public static function none(): self {
		return new self( null, null, null, null );
	}

	/**
	 * Weight in grams, or null if not yet recorded.
	 */
	public function weight_grams(): ?int {
		return $this->weight_grams;
	}

	/**
	 * Length in millimetres, or null.
	 */
	public function length_mm(): ?int {
		return $this->length_mm;
	}

	/**
	 * Width in millimetres, or null.
	 */
	public function width_mm(): ?int {
		return $this->width_mm;
	}

	/**
	 * Height in millimetres, or null.
	 */
	public function height_mm(): ?int {
		return $this->height_mm;
	}

	/**
	 * Whether a spec has been recorded — true once a weight is known;
	 * dimensions are optional. This is the real data source for the
	 * `package_spec_present` transition guard.
	 */
	public function is_present(): bool {
		return null !== $this->weight_grams;
	}
}
