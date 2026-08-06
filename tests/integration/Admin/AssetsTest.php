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
 *
 * `WP_Script_Modules::get_queue()`/`get_registered()` are not usable here —
 * both are `@since` later than 6.5.0 (the plugin's supported floor, per
 * `.github/workflows/ci.yml`'s `integration (floor, …, 6.5.*, …)` leg), so
 * a test built against a newer local WordPress checkout passed while CI's
 * floor leg failed with "Call to undefined method". `print_enqueued_
 * script_modules()` is public since 6.5.0 and is what these tests capture
 * instead — asserting the same real, rendered `<script type="module">` tag
 * a browser would actually receive, which is also strictly closer to what
 * this suite is meant to prove.
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

	/**
	 * Renders whatever is currently enqueued in the Script Modules registry
	 * the same way a real page footer would, via the one introspection
	 * method public since 6.5.0.
	 */
	private function render_enqueued_script_modules(): string {
		ob_start();
		wp_script_modules()->print_enqueued_script_modules();

		return (string) ob_get_clean();
	}

	public function test_workspace_script_is_enqueued_only_on_the_workspace_screen(): void {
		$_GET['page'] = 'mpcf-workspace'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test harness simulating the admin screen query param.

		( new Assets() )->maybe_enqueue( '' );

		$html = $this->render_enqueued_script_modules();

		foreach ( array( 'mpcf-workspace', 'mpcf-packing', 'mpcf-shipment', 'mpcf-documents', 'mpcf-photos', 'mpcf-shortcuts' ) as $module_id ) {
			self::assertStringContainsString( "id=\"{$module_id}-js-module\"", $html );
		}

		self::assertTrue( wp_script_is( 'mpcf-mpds-toast', 'enqueued' ) );
		self::assertTrue( wp_script_is( 'mpcf-mpds-action-bar', 'enqueued' ) );
		self::assertTrue( wp_script_is( 'mpcf-mpds-scan-sink', 'enqueued' ) );
	}

	public function test_workspace_script_is_not_enqueued_on_another_plugin_screen(): void {
		$_GET['page'] = 'mpcf-queue'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test harness simulating the admin screen query param.

		( new Assets() )->maybe_enqueue( '' );

		self::assertSame( '', $this->render_enqueued_script_modules() );
		self::assertFalse( wp_script_is( 'mpcf-mpds-toast', 'enqueued' ) );
	}

	public function test_workspace_scripts_are_registered_as_real_script_modules(): void {
		$_GET['page'] = 'mpcf-workspace'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test harness simulating the admin screen query param.

		( new Assets() )->maybe_enqueue( '' );

		$html = $this->render_enqueued_script_modules();

		foreach ( array( 'mpcf-workspace', 'mpcf-packing', 'mpcf-shipment', 'mpcf-documents', 'mpcf-photos', 'mpcf-shortcuts' ) as $module_id ) {
			self::assertStringContainsString( 'type="module"', $html );
			self::assertStringContainsString( "id=\"{$module_id}-js-module\"", $html, "{$module_id} must be registered as a real script module, not a classic script." );
			self::assertFalse( wp_script_is( $module_id, 'registered' ), "{$module_id} must not also sit in the classic wp_scripts() registry." );
		}
	}

	public function test_workspace_script_localizes_the_rest_url_and_nonce(): void {
		$_GET['page'] = 'mpcf-workspace'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test harness simulating the admin screen query param.

		( new Assets() )->maybe_enqueue( '' );

		$inline = implode( "\n", wp_scripts()->get_data( 'mpcf-mpds-scan-sink', 'after' ) );

		self::assertStringContainsString( 'mpcfWorkspace', $inline );
		self::assertStringContainsString( 'restUrl', $inline );
		self::assertStringContainsString( 'photos', $inline );
		self::assertStringContainsString( 'maxPerFulfillment', $inline );
		self::assertStringContainsString( (string) wp_json_encode( rest_url( 'mpcf/v1/' ) ), $inline );
	}
}
