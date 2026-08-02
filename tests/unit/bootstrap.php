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
require_once __DIR__ . '/Application/Doubles/InMemoryFulfillmentRepository.php';
require_once __DIR__ . '/Application/Doubles/InMemoryFulfillmentItemRepository.php';
require_once __DIR__ . '/Application/Doubles/InMemoryEventRepository.php';
require_once __DIR__ . '/Application/Doubles/InMemoryOrderSource.php';
require_once __DIR__ . '/Application/Doubles/FixedClock.php';
require_once __DIR__ . '/Application/Doubles/RecordingSubscriber.php';
require_once __DIR__ . '/Application/Doubles/InMemoryShipmentRepository.php';
require_once __DIR__ . '/Application/Doubles/InMemoryPackageRepository.php';
require_once __DIR__ . '/Application/Doubles/InMemoryPackageItemRepository.php';

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

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	// Minimal $wpdb double: no real database in the unit suite, so
	// get_var() always reports "table not found" (every CREATE TABLE in
	// Migrator::step_1_initial_tables() is therefore attempted, harmlessly,
	// on every activate() call) and query() just records what ran.
	$GLOBALS['wpdb'] = new class() {
		/**
		 * @var string
		 */
		public string $prefix = 'wp_';

		/**
		 * @var list<string>
		 */
		public array $queries = array();

		public function get_charset_collate(): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}

		public function prepare( string $query, ...$args ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			$query = str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $query );

			return vsprintf( $query, $args );
		}

		public function esc_like( string $text ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			return addcslashes( $text, '_%\\' );
		}

		public function get_var( string $query ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			unset( $query );

			return null;
		}

		public function query( string $sql ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			$this->queries[] = $sql;

			return true;
		}
	};
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

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		// Unit tests never exercise the is_admin()-gated wiring branch (that
		// needs a real WP bootstrap) — always false here is what makes
		// CompositionRootTest's Plugin::init() calls exercise exactly the
		// same path a front-end/CLI request would.
		return false;
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

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return (string) $url;
	}
}

if ( ! function_exists( 'checked' ) ) {
	function checked( $checked_value, $current = true, $echo = true ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, Universal.NamingConventions.NoReservedKeywordParameterNames.echoFound
		$result = ( (string) $checked_value === (string) $current ) ? ' checked="checked"' : '';

		if ( $echo ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		return $result;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
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
