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
 */
final class ScanMode {

	public const PICKING = 'picking';

	public const PACKING = 'packing';

	/**
	 * Whether `$mode` is a supported scan mode.
	 *
	 * @param string $mode Candidate mode.
	 */
	public static function is_valid( string $mode ): bool {
		return self::PICKING === $mode || self::PACKING === $mode;
	}

	/**
	 * Workflow state required for the mode.
	 *
	 * @param string $mode Scan mode.
	 * @throws \InvalidArgumentException When the mode is unknown.
	 */
	public static function required_state( string $mode ): string {
		if ( self::PICKING === $mode ) {
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
		if ( self::PICKING === $mode ) {
			return 'qty_picked';
		}

		if ( self::PACKING === $mode ) {
			return 'qty_packed';
		}

		throw new \InvalidArgumentException( "Unknown scan mode {$mode}." );
	}
}
