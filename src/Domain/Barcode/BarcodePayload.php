<?php
/**
 * Namespaced MPCF barcode payload value object.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Barcode;

/**
 * Architecture Plan Part IX.2. Stable, versionable, secret-free payloads:
 * `MPCF:<TYPE>:<VALUE>` (optional leading format version: `MPCF:1:F:12`).
 */
final class BarcodePayload {

	public const PREFIX = 'MPCF';

	public const TYPE_FULFILLMENT = 'F';

	public const TYPE_ITEM = 'I';

	public const TYPE_PACKAGE = 'P';

	public const TYPE_PRODUCT = 'PR';

	public const TYPE_VARIATION = 'V';

	public const MAX_LENGTH = 256;

	public const FORMAT_VERSION = 1;

	/**
	 * Payload type letter/code.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Positive integer identity encoded in the payload.
	 *
	 * @var int
	 */
	private int $value;

	/**
	 * Format version (1 for M7).
	 *
	 * @var int
	 */
	private int $format_version;

	/**
	 * Assembles a payload.
	 *
	 * @param string $type           One of the TYPE_* constants.
	 * @param int    $value          Positive identity.
	 * @param int    $format_version Payload format version.
	 */
	private function __construct( string $type, int $value, int $format_version ) {
		$this->type           = $type;
		$this->value          = $value;
		$this->format_version = $format_version;
	}

	/**
	 * Builds a fulfillment identity payload.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public static function fulfillment( int $fulfillment_id ): self {
		return self::of( self::TYPE_FULFILLMENT, $fulfillment_id );
	}

	/**
	 * Builds a fulfillment-item identity payload.
	 *
	 * @param int $item_id Fulfillment item id.
	 */
	public static function item( int $item_id ): self {
		return self::of( self::TYPE_ITEM, $item_id );
	}

	/**
	 * Builds a package identity payload.
	 *
	 * @param int $package_id Package id.
	 */
	public static function package( int $package_id ): self {
		return self::of( self::TYPE_PACKAGE, $package_id );
	}

	/**
	 * Builds a product-id payload.
	 *
	 * @param int $product_id WooCommerce product id.
	 */
	public static function product( int $product_id ): self {
		return self::of( self::TYPE_PRODUCT, $product_id );
	}

	/**
	 * Builds a variation-id payload.
	 *
	 * @param int $variation_id WooCommerce variation id.
	 */
	public static function variation( int $variation_id ): self {
		return self::of( self::TYPE_VARIATION, $variation_id );
	}

	/**
	 * Builds a typed payload.
	 *
	 * @param string $type  TYPE_* constant.
	 * @param int    $value Positive id.
	 * @throws \InvalidArgumentException When type is unknown or value is not positive.
	 */
	public static function of( string $type, int $value ): self {
		if ( ! self::is_known_type( $type ) ) {
			throw new \InvalidArgumentException( "Unknown barcode type {$type}." );
		}

		if ( $value <= 0 ) {
			throw new \InvalidArgumentException( 'Barcode value must be a positive integer.' );
		}

		return new self( $type, $value, self::FORMAT_VERSION );
	}

	/**
	 * Parses a raw scan string. Returns null when the string is not an MPCF
	 * namespaced payload (callers then try SKU matching). Throws nothing —
	 * malformed MPCF-shaped strings return a failed {@see BarcodeParseResult}.
	 *
	 * @param string $raw Raw scanner / typed input.
	 */
	public static function parse( string $raw ): BarcodeParseResult {
		$trimmed = trim( $raw );

		if ( '' === $trimmed ) {
			return BarcodeParseResult::empty_input();
		}

		if ( strlen( $trimmed ) > self::MAX_LENGTH ) {
			return BarcodeParseResult::malformed( 'payload_too_long' );
		}

		if ( ! str_starts_with( strtoupper( $trimmed ), self::PREFIX . ':' ) ) {
			return BarcodeParseResult::not_namespaced( $trimmed );
		}

		// Preserve original casing only for the value; types are uppercase.
		$parts = explode( ':', $trimmed );

		if ( count( $parts ) < 3 || count( $parts ) > 4 ) {
			return BarcodeParseResult::malformed( 'bad_segment_count' );
		}

		if ( strtoupper( $parts[0] ) !== self::PREFIX ) {
			return BarcodeParseResult::malformed( 'bad_prefix' );
		}

		$format_version = self::FORMAT_VERSION;
		$type_index     = 1;
		$value_index    = 2;

		if ( 4 === count( $parts ) ) {
			if ( ! ctype_digit( $parts[1] ) || (int) $parts[1] < 1 ) {
				return BarcodeParseResult::malformed( 'bad_format_version' );
			}

			$format_version = (int) $parts[1];
			$type_index     = 2;
			$value_index    = 3;
		}

		$type  = strtoupper( $parts[ $type_index ] );
		$value = $parts[ $value_index ];

		if ( ! self::is_known_type( $type ) ) {
			return BarcodeParseResult::malformed( 'unknown_type' );
		}

		if ( ! ctype_digit( $value ) || (int) $value <= 0 ) {
			return BarcodeParseResult::malformed( 'bad_value' );
		}

		return BarcodeParseResult::ok( new self( $type, (int) $value, $format_version ) );
	}

	/**
	 * Deterministic wire encoding (versionless form — preferred for M7 labels).
	 */
	public function encode(): string {
		return self::PREFIX . ':' . $this->type . ':' . $this->value;
	}

	/**
	 * Versioned encoding for forward-compatible labels.
	 */
	public function encode_versioned(): string {
		return self::PREFIX . ':' . $this->format_version . ':' . $this->type . ':' . $this->value;
	}

	/**
	 * Payload type.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Encoded identity.
	 */
	public function value(): int {
		return $this->value;
	}

	/**
	 * Format version.
	 */
	public function format_version(): int {
		return $this->format_version;
	}

	/**
	 * Whether `$type` is a supported TYPE_* constant.
	 *
	 * @param string $type Candidate type.
	 */
	public static function is_known_type( string $type ): bool {
		return in_array(
			$type,
			array(
				self::TYPE_FULFILLMENT,
				self::TYPE_ITEM,
				self::TYPE_PACKAGE,
				self::TYPE_PRODUCT,
				self::TYPE_VARIATION,
			),
			true
		);
	}
}
