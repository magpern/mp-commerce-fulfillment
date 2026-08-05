<?php
/**
 * Deterministic seed for the Playwright suite: several identical, paid
 * WooCommerce orders, each turned into its own fulfillment by the
 * plugin's real intake hook — the same path a live store uses, never a
 * direct table insert. Run via `wp eval-file tests/browser/seed.php`
 * against a site with WooCommerce and this plugin already active.
 *
 * Specs claim ids atomically from the JSON list written below (see
 * `tests/browser/claim-seed.js`) so concurrent chromium/firefox workers
 * never mutate the same fulfillment, and queue-row index shifts after
 * ship/complete cannot steal another test's fixture.
 *
 * @package MPCommerceFulfillment
 */

if ( ! function_exists( 'wc_create_order' ) ) {
	fwrite( STDERR, "WooCommerce is not active — seed aborted.\n" );
	exit( 1 );
}

// Enough for packing + keyboard + accessibility across both browser
// projects, plus a cushion for CI retries.
const MPCF_SEED_ORDER_COUNT = 40;

$product = new WC_Product_Simple();
$product->set_name( 'Browser Test Widget' );
$product->set_regular_price( '19.99' );
$product->set_sku( 'BROWSER-TEST-WIDGET' );
$product->set_manage_stock( false );
$product->save();

$order_ids       = array();
$fulfillment_ids = array();

global $wpdb;
$table = $wpdb->prefix . 'mpcf_fulfillments';

for ( $i = 0; $i < MPCF_SEED_ORDER_COUNT; $i++ ) {
	$order = wc_create_order();
	$order->add_product( $product, 2 );
	$order->set_address(
		array(
			'first_name' => 'Ada',
			'last_name'  => 'Lovelace',
			'address_1'  => '1 Analytical Engine Way',
			'city'       => 'London',
			'postcode'   => 'EC1A 1BB',
			'country'    => 'GB',
		),
		'shipping'
	);
	$order->calculate_totals();

	// Triggers MPCF\Woo\IntakeHooks — the plugin's real intake path, not
	// a direct mpcf_fulfillments insert, so this seed exercises the same
	// order-paid → fulfillment-created flow a live store relies on.
	$order->update_status( 'processing' );

	$order_id = (int) $order->get_id();
	$order_ids[] = $order_id;

	$fulfillment_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$order_id
		)
	);

	if ( $fulfillment_id <= 0 ) {
		fwrite( STDERR, "Seed failed: no fulfillment for order {$order_id}\n" );
		exit( 1 );
	}

	$fulfillment_ids[] = $fulfillment_id;
}

$auth_dir = dirname( __FILE__ ) . '/.auth';
if ( ! is_dir( $auth_dir ) && ! mkdir( $auth_dir, 0755, true ) && ! is_dir( $auth_dir ) ) {
	fwrite( STDERR, "Seed failed: could not create {$auth_dir}\n" );
	exit( 1 );
}

$ids_file    = $auth_dir . '/seed-fulfillments.json';
$cursor_file = $auth_dir . '/seed-claim.cursor';

if ( false === file_put_contents( $ids_file, wp_json_encode( array_values( $fulfillment_ids ) ) ) ) {
	fwrite( STDERR, "Seed failed: could not write {$ids_file}\n" );
	exit( 1 );
}

file_put_contents( $cursor_file, '0' );

echo implode( ',', $order_ids );
