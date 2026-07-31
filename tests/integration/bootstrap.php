<?php
/**
 * Integration test bootstrap: loads the WordPress test suite with
 * WooCommerce and this plugin active, HPOS forced on.
 *
 * The WordPress core install is provisioned by tests/bin/install-wp.sh. The
 * plugin is loaded through the symlink inside the test install's plugins
 * directory so WooCommerce's FeaturesController recognizes it as a real
 * plugin.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

$mpcf_root = dirname( __DIR__, 2 );

require_once $mpcf_root . '/vendor/autoload.php';

$mpcf_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $mpcf_tests_dir ) {
	$mpcf_tests_dir = $mpcf_root . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}

require_once $mpcf_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		require WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		require WP_PLUGIN_DIR . '/mp-commerce-fulfillment/mp-commerce-fulfillment.php';
	}
);

tests_add_filter(
	'setup_theme',
	function () {
		\WC_Install::install();

		// Force High-Performance Order Storage on so the integration suite
		// proves invariant I2 under the backend this plugin is required to
		// support, not just the legacy default.
		update_option( 'woocommerce_feature_custom_order_tables_enabled', 'yes' );

		$mpcf_sync = \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class;
		if ( function_exists( 'wc_get_container' ) && class_exists( $mpcf_sync ) ) {
			wc_get_container()->get( $mpcf_sync )->create_database_tables();
			update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
			update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		}

		$GLOBALS['wp_roles'] = new \WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}
);

require_once $mpcf_tests_dir . '/includes/bootstrap.php';
