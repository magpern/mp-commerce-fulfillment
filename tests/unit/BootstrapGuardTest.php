<?php
/**
 * Guards invariant I12's "no deactivation hook" half at the source level —
 * the integration suite proves activation/reactivation lose nothing, but
 * the absence of a deactivation hook is a static fact best asserted
 * directly against the main file.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Main-file bootstrap guard tests.
 */
final class BootstrapGuardTest extends TestCase {

	private const MAIN_FILE = __DIR__ . '/../../mp-commerce-fulfillment.php';

	public function test_no_deactivation_hook_is_registered(): void {
		$source = (string) file_get_contents( self::MAIN_FILE );

		self::assertStringNotContainsString(
			'register_deactivation_hook',
			$source,
			'Invariant I12: deactivation must remove nothing, so no deactivation hook may ever be registered.'
		);
	}

	public function test_activation_hook_is_registered(): void {
		$source = (string) file_get_contents( self::MAIN_FILE );

		self::assertStringContainsString( 'register_activation_hook', $source );
	}

	public function test_hpos_and_blocks_compatibility_are_declared_unconditionally(): void {
		$source = (string) file_get_contents( self::MAIN_FILE );

		self::assertStringContainsString( 'custom_order_tables', $source );
		self::assertStringContainsString( 'cart_checkout_blocks', $source );

		// The declaration must not be gated behind class_exists( Plugin::class )
		// — it has to register even when the autoloader/Plugin is unavailable.
		preg_match( '/before_woocommerce_init.*?\}\s*\);/s', $source, $match );

		self::assertNotEmpty( $match, 'before_woocommerce_init callback not found.' );
		self::assertStringNotContainsString( 'MPCF\\Plugin', $match[0] );
	}
}
