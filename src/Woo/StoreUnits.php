<?php
/**
 * Reads the store's own configured weight/dimension display units.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Woo;

/**
 * Architecture Plan §IV.6: weight and dimensions are stored canonically as
 * integer grams/millimetres (D15) — display conversion is a UI concern,
 * sourced from WooCommerce's own `woocommerce_weight_unit`/
 * `woocommerce_dimension_unit` settings through this port, deliberately
 * **not** a new MPCF setting or user meta, since WooCommerce already owns
 * that preference and duplicating it would be a second source of truth.
 *
 * Stateless: every method reads the current option value fresh, exactly
 * like a direct `get_option()` call would, so this class carries no state
 * of its own and needs no constructor.
 */
final class StoreUnits {

	/**
	 * Grams per one unit, for every weight unit WooCommerce offers.
	 */
	private const GRAMS_PER_WEIGHT_UNIT = array(
		'kg'  => 1000.0,
		'g'   => 1.0,
		'lbs' => 453.59237,
		'oz'  => 28.349523125,
	);

	/**
	 * Millimetres per one unit, for every dimension unit WooCommerce offers.
	 */
	private const MM_PER_DIMENSION_UNIT = array(
		'm'  => 1000.0,
		'cm' => 10.0,
		'mm' => 1.0,
		'in' => 25.4,
		'yd' => 914.4,
	);

	/**
	 * The store's configured weight display unit.
	 */
	public function weight_unit_label(): string {
		$unit = (string) get_option( 'woocommerce_weight_unit', 'kg' );

		return isset( self::GRAMS_PER_WEIGHT_UNIT[ $unit ] ) ? $unit : 'kg';
	}

	/**
	 * The store's configured dimension display unit.
	 */
	public function dimension_unit_label(): string {
		$unit = (string) get_option( 'woocommerce_dimension_unit', 'cm' );

		return isset( self::MM_PER_DIMENSION_UNIT[ $unit ] ) ? $unit : 'cm';
	}

	/**
	 * Canonical grams as a decimal string in the store's display unit, for
	 * pre-filling a form field. `''` for `null` (nothing recorded yet), so
	 * an empty field renders empty rather than "0".
	 *
	 * @param int|null $grams Canonical weight, or null if unset.
	 */
	public function grams_to_display( ?int $grams ): string {
		if ( null === $grams ) {
			return '';
		}

		return self::format( $grams / self::GRAMS_PER_WEIGHT_UNIT[ $this->weight_unit_label() ] );
	}

	/**
	 * Canonical millimetres as a decimal string in the store's display
	 * unit, for pre-filling a form field.
	 *
	 * @param int|null $millimetres Canonical length, or null if unset.
	 */
	public function mm_to_display( ?int $millimetres ): string {
		if ( null === $millimetres ) {
			return '';
		}

		return self::format( $millimetres / self::MM_PER_DIMENSION_UNIT[ $this->dimension_unit_label() ] );
	}

	/**
	 * How many grams one display unit is worth — what
	 * `assets/admin/js/shipment.js` multiplies a typed value by before
	 * sending `weight_grams` to the API, since canonical storage is always
	 * grams regardless of what the operator sees.
	 */
	public function grams_per_display_unit(): float {
		return self::GRAMS_PER_WEIGHT_UNIT[ $this->weight_unit_label() ];
	}

	/**
	 * How many millimetres one display unit is worth — the dimension
	 * counterpart of {@see grams_per_display_unit()}.
	 */
	public function mm_per_display_unit(): float {
		return self::MM_PER_DIMENSION_UNIT[ $this->dimension_unit_label() ];
	}

	/**
	 * Trims a trailing `.00`/`.0` so a whole-number conversion displays as
	 * `1` rather than `1.00`, without losing precision for a fractional one.
	 *
	 * @param float $value Converted value.
	 */
	private static function format( float $value ): string {
		return rtrim( rtrim( sprintf( '%.2f', $value ), '0' ), '.' );
	}
}
