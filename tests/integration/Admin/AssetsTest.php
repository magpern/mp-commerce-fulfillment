<?php
/**
 * Integration tests for the plugin-owned asset enqueueing, against a real
 * WordPress script registry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Admin;

use MPCF\Admin\Assets;
use WP_UnitTestCase;

/**
 * Integration tests for the plugin-owned asset enqueueing, against a real
 * WordPress script registry.
 */
final class AssetsTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// A fresh registry per test: wp_enqueue_script()/wp_add_inline_script()
		// otherwise accumulate across tests in this class, since WP core's
		// own per-test reset does not clear the scripts queue.
		$GLOBALS['wp_scripts'] = new \WP_Scripts(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- A WordPress core global, not a plugin symbol.
	}

	public function test_workspace_script_is_enqueued_only_on_the_workspace_screen(): void {
		$_GET['page'] = 'mpcf-workspace'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test harness simulating the admin screen query param.

		( new Assets() )->maybe_enqueue( '' );

		self::assertTrue( wp_script_is( 'mpcf-workspace', 'enqueued' ) );
		self::assertTrue( wp_script_is( 'mpcf-packing', 'enqueued' ) );
		self::assertTrue( wp_script_is( 'mpcf-shortcuts', 'enqueued' ) );
		self::assertTrue( wp_script_is( 'mpcf-mpds-toast', 'enqueued' ) );
		self::assertTrue( wp_script_is( 'mpcf-mpds-action-bar', 'enqueued' ) );
		self::assertTrue( wp_script_is( 'mpcf-mpds-scan-sink', 'enqueued' ) );
	}

	public function test_workspace_script_is_not_enqueued_on_another_plugin_screen(): void {
		$_GET['page'] = 'mpcf-queue'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test harness simulating the admin screen query param.

		( new Assets() )->maybe_enqueue( '' );

		self::assertFalse( wp_script_is( 'mpcf-workspace', 'enqueued' ) );
		self::assertFalse( wp_script_is( 'mpcf-packing', 'enqueued' ) );
		self::assertFalse( wp_script_is( 'mpcf-shortcuts', 'enqueued' ) );
		self::assertFalse( wp_script_is( 'mpcf-mpds-toast', 'enqueued' ) );
	}

	public function test_workspace_script_localizes_the_rest_url_and_nonce(): void {
		$_GET['page'] = 'mpcf-workspace'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test harness simulating the admin screen query param.

		( new Assets() )->maybe_enqueue( '' );

		$inline = implode( "\n", wp_scripts()->get_data( 'mpcf-workspace', 'before' ) );

		self::assertStringContainsString( 'mpcfWorkspace', $inline );
		self::assertStringContainsString( 'restUrl', $inline );
		self::assertStringContainsString( (string) wp_json_encode( rest_url( 'mpcf/v1/' ) ), $inline );
	}
}
