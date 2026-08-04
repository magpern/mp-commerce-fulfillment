<?php
/**
 * Shared presentation helper for customer name snapshots in admin lists.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

/**
 * Formats empty customer snapshots so list rows stay scannable.
 */
final class CustomerNameDisplay {

	/**
	 * Returns the snapshot when present, otherwise a clear fallback label.
	 *
	 * @param string $snapshot Customer name snapshot (may be empty).
	 */
	public static function label( string $snapshot ): string {
		$trimmed = trim( $snapshot );

		if ( '' !== $trimmed ) {
			return $trimmed;
		}

		return __( 'No customer name', 'mp-commerce-fulfillment' );
	}
}
