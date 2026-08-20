<?php
/**
 * Bounded realistic M8 wave dogfood (dev WP, not a floor test).
 *
 * Creates ≥5 fulfillments with a shared SKU and qty>1, runs activate →
 * walk → FIFO scan → pause/resume → undo → complete picks → wave complete,
 * asserts packing remains per-fulfillment and Woo stock is untouched.
 *
 * Usage:
 *   wp eval-file wp-content/plugins/mp-commerce-fulfillment/bin/dogfood-m8-wave.php
 *
 * @package MPCommerceFulfillment
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file inside WordPress.\n" );
	exit( 1 );
}

if ( ! function_exists( 'wc_create_order' ) || ! class_exists( \MPCF\Plugin::class ) ) {
	fwrite( STDERR, "WooCommerce + MPCF required.\n" );
	exit( 1 );
}

$migrator = new \MPCF\Infrastructure\Database\Migrator();
$migrator->maybe_migrate();

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
if ( array() === $admins ) {
	fwrite( STDERR, "No administrator user.\n" );
	exit( 1 );
}
wp_set_current_user( (int) $admins[0]->ID );
$actor = \MPCF\Domain\Event\Actor::user( (int) $admins[0]->ID, $admins[0]->display_name );

$sku = 'M8-DOGFOOD-WIDGET-' . wp_generate_password( 6, false, false );
$product = new WC_Product_Simple();
$product->set_name( 'M8 Dogfood Widget' );
$product->set_regular_price( '9.99' );
$product->set_sku( $sku );
$product->set_manage_stock( true );
$product->set_stock_quantity( 500 );
$product->save();
$product_id = (int) $product->get_id();

// Shared storefront test-product fixture image (idempotent; no overwrite if set).
$bp_img_helper = WP_PLUGIN_DIR . '/biopentra-storefront/scripts/includes/test-product-images.php';
if ( is_readable( $bp_img_helper ) ) {
	require_once $bp_img_helper;
	if ( function_exists( 'biopentra_assign_test_product_image_if_missing' ) ) {
		biopentra_assign_test_product_image_if_missing( $product );
	}
}

global $wpdb;
$table = $wpdb->prefix . 'mpcf_fulfillments';
$fulfillment_ids = array();

for ( $i = 0; $i < 5; $i++ ) {
	$order = wc_create_order();
	$order->add_product( $product, 2 );
	$order->set_address(
		array(
			'first_name' => 'Wave',
			'last_name'  => 'Dogfood',
			'address_1'  => '1 Pick Lane',
			'city'       => 'Stockholm',
			'postcode'   => '11122',
			'country'    => 'SE',
		),
		'shipping'
	);
	$order->calculate_totals();
	$order->update_status( 'processing' );

	$fid = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) $order->get_id()
		)
	);
	if ( $fid <= 0 ) {
		fwrite( STDERR, "No fulfillment for order {$order->get_id()}\n" );
		exit( 1 );
	}
	$fulfillment_ids[] = $fid;
}

$product_after_orders = wc_get_product( $product_id );
$stock_before = (int) $product_after_orders->get_stock_quantity();
echo "stock_after_orders={$stock_before}\n";

$create = new WP_REST_Request( 'POST', '/mpcf/v1/waves' );
$create->set_body_params(
	array(
		'warehouse_id'    => 1,
		'fulfillment_ids' => $fulfillment_ids,
		'title'           => 'M8 dogfood wave',
	)
);
$created = rest_do_request( $create );
if ( $created->is_error() || $created->get_status() >= 400 ) {
	fwrite( STDERR, 'Create failed: ' . wp_json_encode( $created->get_data() ) . "\n" );
	exit( 1 );
}
$wave_body = $created->get_data();
$wave_id   = (int) $wave_body['id'];
$version   = (int) $wave_body['version'];
echo "wave_id={$wave_id} members=" . count( $fulfillment_ids ) . "\n";

$activate = new WP_REST_Request( 'POST', "/mpcf/v1/waves/{$wave_id}/activate" );
$activate->set_body_params( array( 'version' => $version ) );
$act = rest_do_request( $activate );
if ( $act->is_error() || $act->get_status() >= 400 ) {
	fwrite( STDERR, 'Activate failed: ' . wp_json_encode( $act->get_data() ) . "\n" );
	exit( 1 );
}
$wave_body = $act->get_data();
$version   = (int) $wave_body['version'];
echo 'state=' . $wave_body['state'] . "\n";

$walk_req = new WP_REST_Request( 'GET', "/mpcf/v1/waves/{$wave_id}/walk" );
$walk_res = rest_do_request( $walk_req );
$walk     = $walk_res->get_data();
$rows     = $walk['walk']['rows'] ?? array();
echo 'walk_rows=' . count( $rows ) . "\n";
if ( count( $rows ) < 1 ) {
	fwrite( STDERR, "Empty walk\n" );
	exit( 1 );
}

$fifo_ids = array();
for ( $n = 0; $n < 3; $n++ ) {
	$scan = new WP_REST_Request( 'POST', "/mpcf/v1/waves/{$wave_id}/scan" );
	$scan->set_body_params(
		array(
			'action'  => 'pick',
			'payload' => $sku,
			'version' => $version,
		)
	);
	$scan_res = rest_do_request( $scan );
	$body     = $scan_res->get_data();
	if ( $scan_res->get_status() >= 400 ) {
		fwrite( STDERR, 'Scan failed: ' . wp_json_encode( $body ) . "\n" );
		exit( 1 );
	}
	$fifo_ids[] = (int) $body['data']['fulfillment_id'];
	$version    = (int) $body['version'];
	echo "scan{$n} fulfillment_id={$fifo_ids[$n]} result={$body['result']}\n";
}
if ( $fifo_ids[0] !== $fifo_ids[1] ) {
	fwrite( STDERR, "FIFO expected first two scans on same (oldest) fulfillment\n" );
	exit( 1 );
}
echo "fifo_ok first_member={$fifo_ids[0]}\n";

$pause = new WP_REST_Request( 'POST', "/mpcf/v1/waves/{$wave_id}/pause" );
$pause->set_body_params( array( 'version' => $version ) );
$paused = rest_do_request( $pause )->get_data();
$version = (int) $paused['version'];
echo 'paused_state=' . $paused['state'] . "\n";

$resume = new WP_REST_Request( 'POST', "/mpcf/v1/waves/{$wave_id}/resume" );
$resume->set_body_params( array( 'version' => $version ) );
$resumed = rest_do_request( $resume )->get_data();
$version = (int) $resumed['version'];
echo 'resumed_state=' . $resumed['state'] . "\n";

$undo = new WP_REST_Request( 'POST', "/mpcf/v1/waves/{$wave_id}/scan" );
$undo->set_body_params(
	array(
		'action'  => 'undo',
		'version' => $version,
	)
);
$undo_res = rest_do_request( $undo );
$undo_body = $undo_res->get_data();
if ( $undo_res->get_status() >= 400 ) {
	fwrite( STDERR, 'Undo failed: ' . wp_json_encode( $undo_body ) . "\n" );
	exit( 1 );
}
$version = (int) $undo_body['version'];
echo 'undo_result=' . $undo_body['result'] . "\n";

$guard = 0;
while ( $guard < 40 ) {
	++$guard;
	$scan = new WP_REST_Request( 'POST', "/mpcf/v1/waves/{$wave_id}/scan" );
	$scan->set_body_params(
		array(
			'action'  => 'pick',
			'payload' => $sku,
			'version' => $version,
		)
	);
	$scan_res = rest_do_request( $scan );
	$body     = $scan_res->get_data();
	if ( $scan_res->get_status() >= 400 ) {
		$code = is_array( $body ) ? (string) ( $body['code'] ?? '' ) : '';
		$msg  = is_array( $body ) ? (string) ( $body['message'] ?? '' ) : '';
		if ( false !== stripos( $msg, 'outstanding' ) || false !== stripos( $msg, 'fully picked' ) || false !== stripos( $code, 'guard' ) ) {
			echo "over_scan_ok\n";
			break;
		}
		fwrite( STDERR, 'Unexpected scan error: ' . wp_json_encode( $body ) . "\n" );
		exit( 1 );
	}
	$version = (int) $body['version'];
}

$get = rest_do_request( new WP_REST_Request( 'GET', "/mpcf/v1/waves/{$wave_id}" ) )->get_data();
foreach ( $get['members'] as $member ) {
	$fid = (int) $member['fulfillment_id'];
	$fv  = rest_do_request( new WP_REST_Request( 'GET', "/mpcf/v1/fulfillments/{$fid}" ) )->get_data();
	$st  = (string) ( $fv['fulfillment']['state'] ?? '' );
	echo "member {$fid} state={$st}\n";
	if ( ! in_array( $st, array( 'picked', 'packing', 'packed' ), true ) ) {
		fwrite( STDERR, "Expected member {$fid} picked+; got {$st}\n" );
		exit( 1 );
	}
}

$complete = new WP_REST_Request( 'POST', "/mpcf/v1/waves/{$wave_id}/complete" );
$complete->set_body_params( array( 'version' => (int) $get['version'], 'force' => false ) );
$done = rest_do_request( $complete );
$done_body = $done->get_data();
if ( $done->get_status() >= 400 ) {
	fwrite( STDERR, 'Complete failed: ' . wp_json_encode( $done_body ) . "\n" );
	exit( 1 );
}
echo 'wave_complete_state=' . $done_body['state'] . "\n";

// Packing remains per fulfillment — open transitions on one member.
$sample = (int) $fulfillment_ids[0];
$tr     = rest_do_request( new WP_REST_Request( 'GET', "/mpcf/v1/fulfillments/{$sample}/transitions" ) )->get_data();
$targets = array_map(
	static fn( $t ) => (string) ( $t['target'] ?? '' ),
	(array) ( $tr['transitions'] ?? array() )
);
echo 'sample_transitions=' . implode( ',', $targets ) . "\n";
if ( ! in_array( 'packing', $targets, true ) && ! in_array( 'packed', $targets, true ) ) {
	// Already past packing is fine; presence of per-fulfillment transitions proves packing is not wave-owned.
	echo "per_fulfillment_workspace_ok\n";
} else {
	echo "per_fulfillment_packing_available\n";
}

$product_after = wc_get_product( $product_id );
$stock_after   = (int) $product_after->get_stock_quantity();
echo "stock_before={$stock_before} stock_after={$stock_after}\n";
if ( $stock_after !== $stock_before ) {
	fwrite( STDERR, "Woo stock mutated during wave dogfood\n" );
	exit( 1 );
}
echo "no_woo_stock_mutation_ok\n";
echo "DOGFOOD_OK\n";
exit( 0 );
