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
	 * Pass `$customer_id` to attach a second (or later) order to an existing
	 * customer — required for repeat-customer flag coverage.
	 *
	 * @param int      $quantity    Line item quantity.
	 * @param int|null $customer_id Existing WP user id, or null to create one.
	 * @return WC_Order
	 */
	private function create_paid_order_for_customer( int $quantity = 1, ?int $customer_id = null ): WC_Order {
		if ( null !== $customer_id && $customer_id > 0 ) {
			$user = get_userdata( $customer_id );
			self::assertNotFalse( $user, 'Reuse customer_id must refer to an existing user' );
			$user_id = (int) $user->ID;
			$email   = (string) $user->user_email;
		} else {
			$email    = 'test' . uniqid( '', true ) . '@example.com';
			$username = 'testcustomer' . uniqid( '', true );
			$user_id  = wp_create_user( $username, 'password', $email );

			if ( is_wp_error( $user_id ) ) {
				// Collision is unexpected with uniqid; fall back to a second attempt.
				$email    = 'test' . uniqid( '', true ) . '@example.com';
				$username = 'testcustomer' . uniqid( '', true );
				$user_id  = wp_create_user( $username, 'password', $email );
				if ( is_wp_error( $user_id ) ) {
					self::fail( 'Could not create customer user: ' . $user_id->get_error_message() );
				}
			}

			$user_id = (int) $user_id;
		}

		$product = $this->create_product();

		$order = new WC_Order();
		$order->set_customer_id( $user_id );
		$order->add_product( $product, $quantity );
		$order->set_billing_first_name( 'Jane' );
		$order->set_billing_last_name( 'Doe' );
		$order->set_billing_email( $email );
		$order->set_status( 'pending' );
		$order->calculate_totals();
		$order->save();
		$order->payment_complete();

		return $order;
	}
}
