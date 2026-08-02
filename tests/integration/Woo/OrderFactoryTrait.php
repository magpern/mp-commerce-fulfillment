<?php
/**
 * Shared helper for creating real WooCommerce orders/products in
 * integration tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Woo;

use WC_Order;
use WC_Product_Simple;

/**
 * Builds real `WC_Order`/`WC_Product_Simple` rows against the integration
 * suite's WordPress + WooCommerce (HPOS on) install — never a mock, so
 * these tests prove real CRUD/HPOS behavior, not an assumption about it.
 */
trait OrderFactoryTrait {

	/**
	 * Creates one simple product, priced and saved.
	 *
	 * @param string $name  Product name.
	 * @param string $price Regular price.
	 */
	private function create_product( string $name = 'Test Widget', string $price = '9.99' ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( $price );
		$product->set_status( 'publish' );
		$product->save();

		return $product;
	}

	/**
	 * Creates a paid order (fires `woocommerce_payment_complete`) with one
	 * line item.
	 *
	 * @param int $quantity Line item quantity.
	 */
	private function create_paid_order( int $quantity = 1 ): WC_Order {
		$product = $this->create_product();

		$order = new WC_Order();
		$order->add_product( $product, $quantity );
		$order->set_billing_first_name( 'Jane' );
		$order->set_billing_last_name( 'Doe' );
		$order->set_status( 'pending' );
		$order->calculate_totals();
		$order->save();
		$order->payment_complete();

		return $order;
	}
}
