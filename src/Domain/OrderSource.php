<?php
/**
 * Port to the platform that owns orders.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

/**
 * No non-platform order sources exist in Milestone 1 — this plugin has
 * exactly one implementation, {@see \MPCF\Woo\WooOrderSource} — but the
 * port exists from this milestone so that assumption is architectural, not
 * structural: nothing above this interface ever names the order platform
 * directly (invariant I8 confines that to `src/Woo/`).
 */
interface OrderSource {

	/**
	 * Reads an order by id.
	 *
	 * @param int $order_id Order id.
	 */
	public function find( int $order_id ): ?OrderSnapshot;
}
