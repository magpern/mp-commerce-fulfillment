<?php
/**
 * Unit test bootstrap: composer autoloader plus minimal WordPress function
 * stubs. WordPress is not loaded.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! defined( 'MPCF_VERSION' ) ) {
	define( 'MPCF_VERSION', '0.0.0-test' );
}

if ( ! defined( 'MPCF_PLUGIN_FILE' ) ) {
	define( 'MPCF_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/mp-commerce-fulfillment.php' );
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.Found, Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound
		return $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.Found
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
	function get_role( $role ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return null;
	}
}

if ( ! function_exists( 'add_role' ) ) {
	function add_role( ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $args );
		return null;
	}
}

if ( ! function_exists( 'remove_role' ) ) {
	function remove_role( $role ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	}
}

if ( ! function_exists( 'wp_roles' ) ) {
	function wp_roles() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return new class() {
			/**
			 * Registered role slugs, for tests.
			 *
			 * @var array<string, mixed>
			 */
			public array $roles = array();

			/**
			 * Stub role lookup.
			 *
			 * @param string $name Role slug (ignored).
			 */
			public function get_role( $name ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return null;
			}
		};
	}
}
