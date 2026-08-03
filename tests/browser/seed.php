<?php
/**
 * Deterministic seed for the Playwright suite: several identical, paid
 * WooCommerce orders, each turned into its own fulfillment by the
 * plugin's real intake hook — the same path a live store uses, never a
 * direct table insert. Run via `wp eval-file tests/browser/seed.php`
 * against a site with WooCommerce and this plugin already active.
 *
 * More than one order exists specifically so concurrent Playwright
 * workers/projects (chromium and firefox run as separate projects, by
 * default in parallel) each mutate their own fulfillment rather than
 * racing each other's transitions on a single shared one — every spec
 * that needs "a queued fulfillment" selects one by the Queue row's
 * index matching its own `testInfo.parallelIndex`, never a hardcoded id.
 *
 * @package MPCommerceFulfillment
 */

if ( ! function_exists( 'wc_create_order' ) ) {
	fwrite( STDERR, "WooCommerce is not active — seed aborted.\n" );
	exit( 1 );
}

const MPCF_SEED_ORDER_COUNT = 10;

$product = new WC_Product_Simple();
$product->set_name( 'Browser Test Widget' );
$product->set_regular_price( '19.99' );
$product->set_sku( 'BROWSER-TEST-WIDGET' );
$product->set_manage_stock( false );
$product->save();

$order_ids = array();

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

	$order_ids[] = $order->get_id();
}

echo implode( ',', $order_ids );
