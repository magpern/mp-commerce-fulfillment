<?php
/**
 * Resolves customer billing email from a WooCommerce order.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Woo;

use MPCF\Domain\CustomerEmailLookup;
use WC_Order;

/**
 * I8: WooCommerce order access stays in src/Woo/.
 */
final class WooCustomerEmailLookup implements CustomerEmailLookup {

	/**
	 * Billing/contact email for an order, or null when missing/unknown.
	 *
	 * @param int $order_id Store order id.
	 */
	public function email_for_order( int $order_id ): ?string {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		$email = trim( (string) $order->get_billing_email() );

		return '' !== $email ? $email : null;
	}
}
