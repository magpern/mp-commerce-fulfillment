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
 * CRUD-only, per invariant I2: every read goes through `wc_get_order()` and
 * `WC_Order`/`WC_Order_Item_Product` getters — never a direct legacy
 * post-table read of any kind. That discipline is also what makes this
 * class HPOS-compatible with no special-casing: the CRUD API is the same
 * shape under either storage backend, so there is nothing here that could
 * behave differently between them.
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
			$this->line_items( $order )
		);
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
}
