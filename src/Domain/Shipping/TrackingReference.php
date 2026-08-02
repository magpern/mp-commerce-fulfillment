<?php
/**
 * A shipment's tracking number and, optionally, an overridden display URL.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Shipping;

/**
 * Immutable value object. `url` is normally derived from the carrier's own
 * URL template (see {@see \MPCF\Domain\CarrierRegistry}) — this class only
 * carries an explicit override when an operator supplied one, per
 * Architecture Plan §IV.6.
 */
final class TrackingReference {

	/**
	 * Tracking number, or null if not yet recorded.
	 *
	 * @var string|null
	 */
	private ?string $number;

	/**
	 * Explicit tracking-URL override, or null to derive one from the
	 * carrier's template.
	 *
	 * @var string|null
	 */
	private ?string $url;

	/**
	 * Assembles a reference. Use {@see create()} or {@see none()} instead
	 * of calling this directly.
	 *
	 * @param string|null $number Tracking number, or null.
	 * @param string|null $url    Explicit URL override, or null.
	 */
	private function __construct( ?string $number, ?string $url ) {
		$this->number = ( null !== $number && '' !== $number ) ? $number : null;
		$this->url    = ( null !== $url && '' !== $url ) ? $url : null;
	}

	/**
	 * Builds a reference.
	 *
	 * @param string|null $number Tracking number, or null.
	 * @param string|null $url    Explicit URL override, or null.
	 */
	public static function create( ?string $number, ?string $url = null ): self {
		return new self( $number, $url );
	}

	/**
	 * No tracking recorded yet.
	 */
	public static function none(): self {
		return new self( null, null );
	}

	/**
	 * Tracking number, or null if not yet recorded.
	 */
	public function number(): ?string {
		return $this->number;
	}

	/**
	 * Explicit tracking-URL override, or null to derive one from the
	 * carrier's template.
	 */
	public function url(): ?string {
		return $this->url;
	}

	/**
	 * Whether a tracking number has been recorded.
	 */
	public function is_present(): bool {
		return null !== $this->number;
	}
}
