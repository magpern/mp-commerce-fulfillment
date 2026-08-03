<?php
/**
 * The order-platform-backed OrderSource implementation.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Woo;

use MPCF\Domain\OrderLineSnapshot;
use MPCF\Domain\OrderSnapshot;
use MPCF\Domain\OrderSource;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

/**
 * The only implementation of {@see OrderSource} (invariant I8 confines
 * every symbol this class names to this namespace).
 *
 * CRUD-only, per invariant I2: every read goes through `wc_get_order()`,
 * `wc_get_orders()` and `WC_Order`/`WC_Order_Item_Product` getters — never a
 * direct legacy post-table read of any kind. That discipline is also what
 * makes this class HPOS-compatible with no special-casing: the CRUD API is
 * the same shape under either storage backend, so there is nothing here
 * that could behave differently between them.
 */
final class WooOrderSource implements OrderSource {

	/**
	 * Identifies every order this class reads.
	 */
	public const ORDER_SOURCE = 'woocommerce';

	/**
	 * Reads an order by id.
	 *
	 * @param int $order_id Order id.
	 */
	public function find( int $order_id ): ?OrderSnapshot {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		return OrderSnapshot::create(
			$order_id,
			self::ORDER_SOURCE,
			(string) $order->get_order_number(),
			(string) $order->get_formatted_billing_full_name(),
			(string) $order->get_status(),
			$this->line_items( $order ),
			$this->ship_to_lines( $order ),
			(string) $order->get_customer_note()
		);
	}

	/**
	 * Every order id currently in a given status.
	 *
	 * @param string $status Status to match, without the `wc-` prefix.
	 * @return list<int>
	 */
	public function find_ids_by_status( string $status ): array {
		$ids = wc_get_orders(
			array(
				'status'  => $status,
				'limit'   => -1,
				'return'  => 'ids',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Every line item on an order.
	 *
	 * @param WC_Order $order Order to read line items from.
	 * @return array<int, OrderLineSnapshot>
	 */
	private function line_items( WC_Order $order ): array {
		$lines = array();

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			$lines[] = OrderLineSnapshot::create(
				(int) $item_id,
				(int) $item->get_product_id(),
				(int) $item->get_variation_id(),
				$product instanceof WC_Product ? (string) $product->get_sku() : '',
				(string) $item->get_name(),
				(int) $item->get_quantity()
			);
		}

		return $lines;
	}

	/**
	 * The order's ship-to address, formatted as display lines. Falls back
	 * to the billing address when no shipping address is on file (a
	 * pickup or digital order, or a store that never asked for one
	 * separately) — the packing slip needs *an* address to print, and
	 * billing is the only one guaranteed to exist for a paid order.
	 *
	 * @param WC_Order $order Order to read the address from.
	 * @return list<string>
	 */
	private function ship_to_lines( WC_Order $order ): array {
		$address = $order->get_address( 'shipping' );

		if ( '' === trim( (string) ( $address['address_1'] ?? '' ) ) ) {
			$address = $order->get_address( 'billing' );
		}

		$formatted = WC()->countries->get_formatted_address( $address, "\n" ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WC()'s own accessor, not a plugin symbol.

		if ( '' === $formatted ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( "\n", $formatted ) ) ) );
	}
}
