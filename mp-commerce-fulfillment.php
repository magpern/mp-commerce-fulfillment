<?php
/**
 * Plugin Name: Commerce Fulfillment
 * Plugin URI: https://github.com/magpern/mp-commerce-fulfillment
 * Description: Warehouse fulfillment platform for WooCommerce. WooCommerce remains the order source; Commerce Fulfillment owns everything from "paid" to "delivered" — picking, packing, shipping, tracking, documents and audit.
 * Version: 0.8.0
 * Author: magpern
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mp-commerce-fulfillment
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 * WC tested up to: 10.9
 *
 * Milestone 1 scope: fulfillment core — intake (classic and Blocks
 * checkout), a data-defined workflow engine driving the standard
 * pick/pack/ship workflow, an append-only hash-chained audit trail,
 * Fulfillment Queue/Detail/Dashboard admin screens, Warehouse Operator/Lead
 * roles and capabilities with an optional Operator Mode, and a
 * configurable WooCommerce status bridge. See docs/ARCHITECTURE_PLAN.md
 * Part III and docs/M1_RELEASE_REPORT.md for the full scope and evidence.
 *
 * @package MPCommerceFulfillment
 */

defined( 'ABSPATH' ) || exit;

define( 'MPCF_VERSION', '0.8.0' );
define( 'MPCF_PLUGIN_FILE', __FILE__ );
define( 'MPCF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// PHP version guard. The "Requires PHP" header stops activation on WP 5.1+,
// but a file-drop install can bypass it, so fail closed with a notice.
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Commerce Fulfillment requires PHP 8.1 or newer and is inactive.', 'mp-commerce-fulfillment' )
			);
		}
	);
	return;
}

$mpcf_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $mpcf_autoload ) ) {
	require_once $mpcf_autoload;
}

/*
 * HPOS and Cart/Checkout Blocks compatibility declarations. These must
 * register even when Plugin::init() never runs (autoloader missing,
 * WooCommerce absent) — invariant I2 requires HPOS compatibility to be
 * declared from the first installable release, unconditionally.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MPCF_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MPCF_PLUGIN_FILE, true );
		}
	}
);

if ( class_exists( \MPCF\Plugin::class ) ) {
	register_activation_hook( __FILE__, array( \MPCF\Plugin::class, 'activate' ) );
}

/*
 * No deactivation hook is registered, and that is deliberate: invariant I12
 * requires deactivation to remove nothing and leave the site byte-identical.
 * Data removal happens only in uninstall.php, and only when explicitly
 * opted into via remove_data_on_uninstall.
 */

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'Commerce Fulfillment requires WooCommerce to be installed and active.', 'mp-commerce-fulfillment' )
					);
				}
			);
			return;
		}

		if ( ! class_exists( \MPCF\Plugin::class ) ) {
			// Autoloader missing (composer install not run); stay inert.
			return;
		}

		\MPCF\Plugin::instance()->init();
	}
);
