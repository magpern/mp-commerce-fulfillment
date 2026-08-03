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
 * WordPress script registry. The five plugin-owned workspace behavior
 * modules are real ES modules (`import`/`export`), registered through
 * WordPress's dedicated Script Modules API (`wp_script_modules()`) rather
 * than the classic `wp_scripts()` registry `wp_script_is()` reads — this
 * distinction is exactly what an earlier version of this test suite missed
 * (it asserted against `wp_script_is()`, which stayed happily green while
 * the real `<script>` tag never carried `type="module"` at all; only a
 * real browser loading a real `<script>` tag ever caught that, F22).
 */
final class AssetsTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// A fresh registry per test: wp_enqueue_script()/wp_add_inline_script()
		// otherwise accumulate across tests in this class, since WP core's
		// own per-test reset does not clear the scripts queue.
		$GLOBALS['wp_scripts']        = new \WP_Scripts(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- A WordPress core global, not a plugin symbol.
		$GLOBALS['wp_script_modules'] = new \WP_Script_Modules(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- A WordPress core global, not a plugin symbol.
	}

	public function test_workspace_script_is_enqueued_only_on_the_workspace_screen(): void {
		$_GET['page'] = 'mpcf-workspace'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test harness simulating the admin screen query param.

		( new Assets() )->maybe_enqueue( '' );

		$modules = wp_script_modules()->get_queue();

		self::assertContains( 'mpcf-workspace', $modules );
		self::assertContains( 'mpcf-packing', $modules );
		self::assertContains( 'mpcf-shipment', $modules );
		self::assertContains( 'mpcf-documents', $modules );
		self::assertContains( 'mpcf-shortcuts', $modules );
		self::assertTrue( wp_script_is( 'mpcf-mpds-toast', 'enqueued' ) );
		self::assertTrue( wp_script_is( 'mpcf-mpds-action-bar', 'enqueued' ) );
		self::assertTrue( wp_script_is( 'mpcf-mpds-scan-sink', 'enqueued' ) );
	}

	public function test_workspace_script_is_not_enqueued_on_another_plugin_screen(): void {
		$_GET['page'] = 'mpcf-queue'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test harness simulating the admin screen query param.

		( new Assets() )->maybe_enqueue( '' );

		self::assertSame( array(), wp_script_modules()->get_queue() );
		self::assertFalse( wp_script_is( 'mpcf-mpds-toast', 'enqueued' ) );
	}

	public function test_workspace_scripts_are_registered_as_real_script_modules(): void {
		$_GET['page'] = 'mpcf-workspace'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test harness simulating the admin screen query param.

		( new Assets() )->maybe_enqueue( '' );

		foreach ( array( 'mpcf-workspace', 'mpcf-packing', 'mpcf-shipment', 'mpcf-documents', 'mpcf-shortcuts' ) as $module_id ) {
			self::assertNotNull( wp_script_modules()->get_registered( $module_id ), "{$module_id} must be registered as a real script module, not a classic script." );
		}
	}

	public function test_workspace_script_localizes_the_rest_url_and_nonce(): void {
		$_GET['page'] = 'mpcf-workspace'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test harness simulating the admin screen query param.

		( new Assets() )->maybe_enqueue( '' );

		$inline = implode( "\n", wp_scripts()->get_data( 'mpcf-mpds-scan-sink', 'after' ) );

		self::assertStringContainsString( 'mpcfWorkspace', $inline );
		self::assertStringContainsString( 'restUrl', $inline );
		self::assertStringContainsString( (string) wp_json_encode( rest_url( 'mpcf/v1/' ) ), $inline );
	}
}
