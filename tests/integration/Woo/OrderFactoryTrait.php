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

	/**
	 * Creates a paid order with a real, populated shipping address — for
	 * tests that specifically exercise address formatting (the packing
	 * slip's ship-to block), which {@see create_paid_order()} deliberately
	 * leaves unset since almost none of this suite's other tests care.
	 */
	private function create_paid_order_with_shipping_address(): WC_Order {
		$product = $this->create_product();

		$order = new WC_Order();
		$order->add_product( $product, 1 );
		$order->set_billing_first_name( 'Jane' );
		$order->set_billing_last_name( 'Doe' );
		$order->set_shipping_first_name( 'Anna' );
		$order->set_shipping_last_name( 'Andersson' );
		$order->set_shipping_address_1( 'Storgatan 1' );
		$order->set_shipping_city( 'Stockholm' );
		$order->set_shipping_postcode( '111 22' );
		$order->set_shipping_country( 'SE' );
		$order->set_status( 'pending' );
		$order->calculate_totals();
		$order->save();
		$order->payment_complete();

		return $order;
	}

	/**
	 * Creates a paid order attached to a real WordPress user (customer).
	 * Use this when tests need to exercise cross-order or customer-related
	 * logic that requires a nonzero customer_id.
	 *
	 * @param int $quantity Line item quantity.
	 * @return WC_Order
	 */
	private function create_paid_order_for_customer( int $quantity = 1 ): WC_Order {
		$user_id = wp_create_user( 'testcustomer' . uniqid(), 'password', array( 'user_email' => 'test' . uniqid() . '@example.com' ) );

		if ( is_wp_error( $user_id ) ) {
			// User already exists, find it.
			$user = get_user_by( 'login', 'testcustomer' );
			if ( ! $user ) {
				$user_id = wp_create_user( 'testcustomer' . uniqid(), 'password', array( 'user_email' => 'test' . uniqid() . '@example.com' ) );
			} else {
				$user_id = $user->ID;
			}
		}

		$product = $this->create_product();

		$order = new WC_Order();
		$order->set_customer_id( $user_id );
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
