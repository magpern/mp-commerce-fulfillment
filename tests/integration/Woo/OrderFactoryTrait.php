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

	/**
	 * Adds a real order-item marked as a third-party bundle plugin's kit
	 * parent line (ADR-0008) — a persisted `_ucb_kit` meta value only,
	 * never a call into that plugin's own code (there is none installed in
	 * this suite; that is the point).
	 *
	 * @param WC_Order $order        Order to add the line to.
	 * @param string   $snapshot_json Raw JSON to store under `_ucb_kit`.
	 * @return int The new line item's order_item_id.
	 */
	private function add_kit_parent_line( WC_Order $order, string $snapshot_json = '{"v":1,"kit_id":0,"kit_sku":"KIT-1","kit_qty":1,"components":[]}' ): int {
		$item_id = $order->add_product( $this->create_product( 'Test Kit' ) );
		$item    = $order->get_item( $item_id );
		$item->add_meta_data( '_ucb_kit', $snapshot_json, true );
		$item->save_meta_data();

		return (int) $item_id;
	}

	/**
	 * Adds a real order-item marked as one of that kit's component child
	 * lines — persisted meta only, same rationale as
	 * {@see add_kit_parent_line()}. Zero-priced, mirroring how the bundle
	 * plugin actually leaves component order-item totals (it zero-prices in
	 * the cart, before the order item ever exists).
	 *
	 * @param WC_Order $order           Order to add the line to.
	 * @param int      $parent_item_id  The kit parent's order_item_id.
	 * @param int      $position        0-based position within the kit.
	 * @param string   $name            Component product name.
	 * @param int      $quantity        Component quantity for this line.
	 * @return int The new line item's order_item_id.
	 */
	private function add_kit_component_line( WC_Order $order, int $parent_item_id, int $position, string $name, int $quantity = 1 ): int {
		$item_id = $order->add_product( $this->create_product( $name ), $quantity );
		$item    = $order->get_item( $item_id );
		$item->set_subtotal( 0 );
		$item->set_total( 0 );
		$item->add_meta_data( '_ucb_component', '1', true );
		$item->add_meta_data( '_ucb_parent_item_id', (string) $parent_item_id, true );
		$item->add_meta_data( '_ucb_snapshot_version', '1', true );
		$item->add_meta_data( '_ucb_position', (string) $position, true );
		$item->save_meta_data();

		return (int) $item_id;
	}

	/**
	 * Creates a paid order shaped exactly like a third-party bundle
	 * plugin's Architecture B output: one priced kit-parent line plus N
	 * real, zero-priced component child lines — built entirely from
	 * persisted order-item meta, with no bundle-plugin code involved.
	 *
	 * Pass `$mark_paid = false` to leave the order `pending` — no
	 * `woocommerce_payment_complete`/`woocommerce_order_status_processing`
	 * fires, so the globally-wired `IntakeHooks` does not intake it
	 * synchronously, leaving a test free to drive the Action Scheduler
	 * fallback path directly and compare its result to the synchronous
	 * path (AC7).
	 *
	 * @param array<int, string> $component_names Names for each component line.
	 * @param bool               $mark_paid       Whether to call `payment_complete()`.
	 * @return WC_Order
	 */
	private function create_paid_kit_order( array $component_names = array( 'Component A', 'Component B', 'Component C' ), bool $mark_paid = true ): WC_Order {
		$order          = new WC_Order();
		$parent_item_id = $this->add_kit_parent_line( $order );

		foreach ( array_values( $component_names ) as $position => $name ) {
			$this->add_kit_component_line( $order, $parent_item_id, $position, $name );
		}

		$order->set_billing_first_name( 'Jane' );
		$order->set_billing_last_name( 'Doe' );
		$order->set_status( 'pending' );
		$order->calculate_totals();
		$order->save();

		if ( $mark_paid ) {
			$order->payment_complete();
		}

		return $order;
	}

	/**
	 * Creates one paid order containing two separate kits that both
	 * declare a component with the same name — proving the two component
	 * lines stay distinct order items with their own quantities rather
	 * than being merged (AC2).
	 */
	private function create_paid_order_with_two_kits_sharing_a_component(): WC_Order {
		$order = new WC_Order();

		$kit_one_parent = $this->add_kit_parent_line( $order, '{"v":1,"kit_id":1,"kit_sku":"KIT-1","kit_qty":1,"components":[]}' );
		$this->add_kit_component_line( $order, $kit_one_parent, 0, 'Shared Component', 2 );
		$this->add_kit_component_line( $order, $kit_one_parent, 1, 'Kit One Only', 1 );

		$kit_two_parent = $this->add_kit_parent_line( $order, '{"v":1,"kit_id":2,"kit_sku":"KIT-2","kit_qty":1,"components":[]}' );
		$this->add_kit_component_line( $order, $kit_two_parent, 0, 'Shared Component', 5 );

		$order->set_billing_first_name( 'Jane' );
		$order->set_billing_last_name( 'Doe' );
		$order->set_status( 'pending' );
		$order->calculate_totals();
		$order->save();
		$order->payment_complete();

		return $order;
	}
}
