<?php
/**
 * Explicit Workspace scan modes.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Scan;

/**
 * Architecture Plan Part IX — Picking vs Packing Scan Mode.
 * Part X adds Wave Picking Scan Mode (extends M7; does not replace it).
 */
final class ScanMode {

	public const PICKING = 'picking';

	public const PACKING = 'packing';

	public const WAVE_PICKING = 'wave_picking';

	/**
	 * Whether `$mode` is a supported scan mode.
	 *
	 * @param string $mode Candidate mode.
	 */
	public static function is_valid( string $mode ): bool {
		return in_array( $mode, array( self::PICKING, self::PACKING, self::WAVE_PICKING ), true );
	}

	/**
	 * Workflow state required for the mode.
	 *
	 * @param string $mode Scan mode.
	 * @throws \InvalidArgumentException When the mode is unknown.
	 */
	public static function required_state( string $mode ): string {
		if ( self::PICKING === $mode || self::WAVE_PICKING === $mode ) {
			return 'picking';
		}

		if ( self::PACKING === $mode ) {
			return 'packing';
		}

		throw new \InvalidArgumentException( "Unknown scan mode {$mode}." );
	}

	/**
	 * Quantity field mutated by the mode.
	 *
	 * @param string $mode Scan mode.
	 * @throws \InvalidArgumentException When the mode is unknown.
	 */
	public static function quantity_field( string $mode ): string {
		if ( self::PICKING === $mode || self::WAVE_PICKING === $mode ) {
			return 'qty_picked';
		}

		if ( self::PACKING === $mode ) {
			return 'qty_packed';
		}

		throw new \InvalidArgumentException( "Unknown scan mode {$mode}." );
	}
}
