<?php
/**
 * Unit test bootstrap: composer autoloader plus minimal WordPress function
 * stubs. WordPress is not loaded.
 *
 * Options and roles are backed by simple in-memory stores (`$GLOBALS`) so
 * tests can simulate real activate/uninstall lifecycles rather than only
 * asserting "did not throw". `mpcf_tests_reset_wp_state()` clears both
 * between tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Test doubles never match *Test.php, so PHPUnit's directory-based
// discovery never loads them; required explicitly (house convention).
require_once __DIR__ . '/Doubles/WPRoleDouble.php';

if ( ! defined( 'MPCF_VERSION' ) ) {
	define( 'MPCF_VERSION', '0.0.0-test' );
}

if ( ! defined( 'MPCF_PLUGIN_FILE' ) ) {
	define( 'MPCF_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/mp-commerce-fulfillment.php' );
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	// Lets tests `include` uninstall.php directly without it exiting. Named
	// by the WordPress uninstall.php contract, not by this plugin.
	define( 'WP_UNINSTALL_PLUGIN', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
}

if ( ! isset( $GLOBALS['mpcf_test_options'] ) ) {
	$GLOBALS['mpcf_test_options'] = array();
}

if ( ! isset( $GLOBALS['mpcf_test_roles'] ) ) {
	$GLOBALS['mpcf_test_roles'] = array();
}

/**
 * Resets the in-memory options/roles stores. Call from `setUp()` in any test
 * that exercises activation or uninstall so state never leaks between tests.
 */
function mpcf_tests_reset_wp_state(): void {
	$GLOBALS['mpcf_test_options'] = array();
	$GLOBALS['mpcf_test_roles']   = array();
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound
		return $GLOBALS['mpcf_test_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$GLOBALS['mpcf_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $GLOBALS['mpcf_test_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $args );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $args );
	}
}

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	function load_plugin_textdomain( ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $args );
		return true;
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return basename( (string) $file );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'get_role' ) ) {
	function get_role( $role ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['mpcf_test_roles'][ $role ] ?? null;
	}
}

if ( ! function_exists( 'add_role' ) ) {
	function add_role( $role, $display_name, $capabilities = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( isset( $GLOBALS['mpcf_test_roles'][ $role ] ) ) {
			return null;
		}

		$GLOBALS['mpcf_test_roles'][ $role ] = new \WP_Role( $role, $capabilities );

		return $GLOBALS['mpcf_test_roles'][ $role ];
	}
}

if ( ! function_exists( 'remove_role' ) ) {
	function remove_role( $role ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $GLOBALS['mpcf_test_roles'][ $role ] );
	}
}

if ( ! function_exists( 'wp_roles' ) ) {
	function wp_roles() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return new class() {
			/**
			 * Registered role slugs (mirrors WP_Roles::$roles' key set).
			 *
			 * @var array<string, bool>
			 */
			public array $roles;

			public function __construct() {
				$this->roles = array_fill_keys( array_keys( $GLOBALS['mpcf_test_roles'] ), true );
			}

			/**
			 * Looks up a role by name from the shared in-memory store.
			 *
			 * @param string $name Role slug.
			 */
			public function get_role( $name ) {
				return $GLOBALS['mpcf_test_roles'][ $name ] ?? null;
			}
		};
	}
}
