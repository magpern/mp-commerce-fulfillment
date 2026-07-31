<?php
/**
 * Binds the plugin header version, the MPCF_VERSION constant and the
 * readme.txt Stable tag together so a release can never bump one and forget
 * the others.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Version-parity tests.
 */
final class PluginVersionTest extends TestCase {

	private const ROOT = __DIR__ . '/../..';

	public function test_header_constant_and_readme_stable_tag_agree(): void {
		$main_file = (string) file_get_contents( self::ROOT . '/mp-commerce-fulfillment.php' );

		preg_match( '/^ \* Version:\s*(\S+)/m', $main_file, $header_match );
		preg_match( "/define\\(\\s*'MPCF_VERSION',\\s*'([^']+)'/", $main_file, $constant_match );

		self::assertNotEmpty( $header_match, 'Plugin header must declare a Version.' );
		self::assertNotEmpty( $constant_match, 'mp-commerce-fulfillment.php must define MPCF_VERSION.' );

		$header_version   = $header_match[1];
		$constant_version = $constant_match[1];

		self::assertSame( $header_version, $constant_version, 'Header Version and MPCF_VERSION must match.' );

		$readme = (string) file_get_contents( self::ROOT . '/readme.txt' );
		preg_match( '/^Stable tag:\s*(\S+)/m', $readme, $readme_match );

		self::assertNotEmpty( $readme_match, 'readme.txt must declare a Stable tag.' );
		self::assertSame( $header_version, $readme_match[1], 'readme.txt Stable tag must match the plugin header Version.' );
	}

	public function test_requires_plugins_header_declares_woocommerce(): void {
		$main_file = (string) file_get_contents( self::ROOT . '/mp-commerce-fulfillment.php' );

		self::assertMatchesRegularExpression(
			'/^ \* Requires Plugins:\s*woocommerce/m',
			$main_file,
			'This plugin requires WooCommerce (unlike the sibling plugins where WC is optional).'
		);
	}
}
