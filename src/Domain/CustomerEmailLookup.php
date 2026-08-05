<?php
/**
 * Port for looking up a customer contact email for an order.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

/**
 * Resolved at the Woo edge. Application never names WC order APIs.
 */
interface CustomerEmailLookup {

	/**
	 * Billing/contact email for an order, or null when missing/unknown.
	 *
	 * @param int $order_id Store order id.
	 */
	public function email_for_order( int $order_id ): ?string;
}
