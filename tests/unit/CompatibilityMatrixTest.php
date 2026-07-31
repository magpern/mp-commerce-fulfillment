<?php
/**
 * Binds the plugin header's version floors to docs/COMPATIBILITY.md so a
 * header bump can never silently outrun (or lag) the documented matrix.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Compatibility-matrix documentation-sync test.
 */
final class CompatibilityMatrixTest extends TestCase {

	private const ROOT = __DIR__ . '/../..';

	public function test_plugin_header_floors_match_compatibility_doc(): void {
		$main_file = (string) file_get_contents( self::ROOT . '/mp-commerce-fulfillment.php' );
		$doc       = (string) file_get_contents( self::ROOT . '/docs/COMPATIBILITY.md' );

		preg_match( '/^ \* Requires at least:\s*(\S+)/m', $main_file, $wp_floor );
		preg_match( '/^ \* Requires PHP:\s*(\S+)/m', $main_file, $php_floor );
		preg_match( '/^ \* WC requires at least:\s*(\S+)/m', $main_file, $wc_floor );

		self::assertNotEmpty( $wp_floor );
		self::assertNotEmpty( $php_floor );
		self::assertNotEmpty( $wc_floor );

		self::assertStringContainsString( $php_floor[1], $doc, 'docs/COMPATIBILITY.md must state the PHP floor from the plugin header.' );
		self::assertStringContainsString( $wp_floor[1], $doc, 'docs/COMPATIBILITY.md must state the WordPress floor from the plugin header.' );
		self::assertStringContainsString( $wc_floor[1], $doc, 'docs/COMPATIBILITY.md must state the WooCommerce floor from the plugin header.' );
	}

	public function test_requires_plugins_declares_woocommerce(): void {
		$main_file = (string) file_get_contents( self::ROOT . '/mp-commerce-fulfillment.php' );

		self::assertMatchesRegularExpression( '/^ \* Requires Plugins:\s*woocommerce/m', $main_file );
	}
}
