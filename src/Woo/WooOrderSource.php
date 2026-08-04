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
	 * Paginated lightweight order rows for the Orders overview.
	 *
	 * @param array<int, string> $statuses Status keys without `wc-`; empty = any non-trash status.
	 * @param int                $page     1-indexed page.
	 * @param int                $per_page Rows per page.
	 * @param string             $search   Optional free-text search (order # / customer).
	 */
	public function list_summaries( array $statuses, int $page, int $per_page, string $search = '' ): \MPCF\Domain\OperationalOrderListResult {
		$args = array(
			'limit'    => max( 1, $per_page ),
			'page'     => max( 1, $page ),
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'return'   => 'objects',
		);

		if ( array() !== $statuses ) {
			$args['status'] = $statuses;
		} else {
			$args['status'] = array_keys( wc_get_order_statuses() );
		}

		$search = trim( $search );

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$result = wc_get_orders( $args );

		if ( ! is_object( $result ) || ! isset( $result->orders, $result->total ) ) {
			return new \MPCF\Domain\OperationalOrderListResult( array(), 0, $page, $per_page );
		}

		$items = array();

		foreach ( $result->orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$summary = $this->summary_from_order( $order );

			if ( null !== $summary ) {
				$items[] = $summary;
			}
		}

		return new \MPCF\Domain\OperationalOrderListResult( $items, (int) $result->total, $page, $per_page );
	}

	/**
	 * Lightweight order rows for the given ids, preserving input order.
	 *
	 * @param array<int, int> $order_ids Order ids.
	 * @return list<\MPCF\Domain\OperationalOrderSummary>
	 */
	public function summaries_by_ids( array $order_ids ): array {
		$items = array();

		foreach ( $order_ids as $order_id ) {
			$order_id = (int) $order_id;

			if ( $order_id <= 0 ) {
				continue;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$summary = $this->summary_from_order( $order );

			if ( null !== $summary ) {
				$items[] = $summary;
			}
		}

		return $items;
	}

	/**
	 * Builds an operational summary from a WC order object.
	 *
	 * @param WC_Order $order Order.
	 */
	private function summary_from_order( WC_Order $order ): ?\MPCF\Domain\OperationalOrderSummary {
		$created = $order->get_date_created();

		if ( null === $created ) {
			return null;
		}

		return \MPCF\Domain\OperationalOrderSummary::create(
			(int) $order->get_id(),
			(string) $order->get_order_number(),
			(string) $order->get_formatted_billing_full_name(),
			(string) $order->get_status(),
			( new \DateTimeImmutable() )->setTimestamp( $created->getTimestamp() )
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
