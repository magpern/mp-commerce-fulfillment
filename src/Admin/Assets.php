<?php
/**
 * Enqueues MPDS and plugin-owned admin assets, scoped to MPCF screens.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

use MPCF\Capabilities;
use MPCF\Settings;

/**
 * Loads the vendored MPDS stylesheet/scripts (invariant: no runtime
 * dependency on the design system repo — these are this plugin's own
 * copies under `assets/mpds/`, vendored by `bin/sync-mpds.sh`) plus this
 * plugin's own small override stylesheet, only on the plugin's own screens
 * (Architecture Plan Sec9.1: "Assets are gated to plugin screens only").
 */
final class Assets {

	/**
	 * Every admin page slug this plugin owns.
	 */
	private const SCREEN_SLUGS = array( 'mpcf-dashboard', 'mpcf-queue', 'mpcf-orders', 'mpcf-documents', 'mpcf-analytics', 'mpcf-settings', 'mpcf-fulfillment-detail', 'mpcf-workspace', 'mpcf-wave' );

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_filter( 'admin_body_class', array( $this, 'maybe_add_body_class' ) );
	}

	/**
	 * Enqueues assets only on this plugin's own admin screens.
	 *
	 * @param string $hook_suffix The current admin page's hook suffix.
	 */
	public function maybe_enqueue( string $hook_suffix ): void {
		unset( $hook_suffix );

		if ( ! self::is_plugin_screen() ) {
			return;
		}

		wp_enqueue_style( 'mpcf-mpds-tokens', MPCF_PLUGIN_URL . 'assets/mpds/css/tokens.css', array(), MPCF_VERSION );
		wp_enqueue_style( 'mpcf-mpds-components', MPCF_PLUGIN_URL . 'assets/mpds/css/components.css', array( 'mpcf-mpds-tokens' ), MPCF_VERSION );
		wp_enqueue_style( 'mpcf-admin', MPCF_PLUGIN_URL . 'assets/admin/css/mpcf-admin.css', array( 'mpcf-mpds-components' ), MPCF_VERSION );

		// Every vendored MPDS behavior module (this trio, plus toast/
		// action-bar/scan-sink below) is a self-contained `(function(){...})()`
		// IIFE — no `import`/`export` — so a plain classic script is
		// correct; there is no WordPress "type" script-data key that
		// changes a classic script's `<script>` tag (that only exists for
		// the dedicated Script Modules API, `wp_enqueue_script_module()`,
		// which is for real ES modules only — see enqueue_workspace_assets()).
		wp_enqueue_script( 'mpcf-mpds-data-table-keynav', MPCF_PLUGIN_URL . 'assets/mpds/js/data-table-keynav.js', array(), MPCF_VERSION, true );
		wp_enqueue_script( 'mpcf-mpds-drawer', MPCF_PLUGIN_URL . 'assets/mpds/js/drawer.js', array(), MPCF_VERSION, true );
		wp_enqueue_script( 'mpcf-mpds-modal', MPCF_PLUGIN_URL . 'assets/mpds/js/modal.js', array(), MPCF_VERSION, true );

		if ( self::is_documents_screen() ) {
			wp_enqueue_script( 'wp-api-request' );
			wp_add_inline_script(
				'wp-api-request',
				sprintf(
					'window.mpcfWorkspace = window.mpcfWorkspace || { restUrl: %s, nonce: %s };',
					wp_json_encode( rest_url( 'mpcf/v1/' ) ),
					wp_json_encode( wp_create_nonce( 'wp_rest' ) )
				)
			);
		}

		if ( self::is_workspace_screen() ) {
			$this->enqueue_workspace_assets();
		}

		if ( self::is_wave_screen() ) {
			$this->enqueue_wave_assets();
		}

		if ( self::is_queue_screen() ) {
			$this->enqueue_queue_wave_assets();
		}

		if ( self::is_detail_screen() ) {
			$this->enqueue_detail_photo_assets();
		}

		if ( self::is_settings_screen() ) {
			wp_enqueue_script( 'mpcf-mpds-sticky-save', MPCF_PLUGIN_URL . 'assets/mpds/js/sticky-save.js', array(), MPCF_VERSION, true );
		}
	}

	/**
	 * Enqueues the Packing Workspace's own behavior modules — the vendored
	 * MPDS IIFEs its markup depends on (toast, action-bar, scan-sink;
	 * classic scripts, same as the trio in {@see maybe_enqueue()}) plus
	 * this plugin's own bootstrap, checklist, shipment, documents, photos and
	 * keyboard-shortcut modules, and localizes the small config object
	 * `assets/admin/js/api.js` reads its REST base URL and nonce from.
	 *
	 * The plugin-owned modules use real `import`/`export` (unlike every
	 * vendored file here), so they are registered through WordPress's
	 * dedicated Script Modules API (`wp_enqueue_script_module()`, since
	 * WP 6.5) — never `wp_enqueue_script()` plus a `type` script-data key,
	 * which is not a recognized key on the classic Scripts API and
	 * silently produces a `<script>` tag with no `type="module"` at all
	 * (found the hard way: every `import` then throws
	 * `Cannot use import statement outside a module` and none of this
	 * plugin's own workspace JS runs — a defect the Playwright suite's
	 * first real page load caught, F22, since no PHPUnit test ever
	 * renders a real `<script>` tag to inspect). `packing.js`/`shipment.js`/
	 * `documents.js`/`photos.js`/`shortcuts.js` load after `workspace.js` since each
	 * reads `window.MpcfWorkspace`, which `workspace.js` sets on its own
	 * `DOMContentLoaded` listener — script modules still execute in
	 * registration order when (as here) none declares the others as a
	 * module dependency, the same way deferred classic scripts do.
	 */
	private function enqueue_workspace_assets(): void {
		wp_enqueue_script( 'mpcf-mpds-toast', MPCF_PLUGIN_URL . 'assets/mpds/js/toast.js', array(), MPCF_VERSION, true );
		wp_enqueue_script( 'mpcf-mpds-action-bar', MPCF_PLUGIN_URL . 'assets/mpds/js/action-bar.js', array(), MPCF_VERSION, true );
		wp_enqueue_script( 'mpcf-mpds-scan-sink', MPCF_PLUGIN_URL . 'assets/mpds/js/scan-sink.js', array(), MPCF_VERSION, true );

		wp_enqueue_script_module( 'mpcf-workspace', MPCF_PLUGIN_URL . 'assets/admin/js/workspace.js', array(), MPCF_VERSION );
		wp_enqueue_script_module( 'mpcf-packing', MPCF_PLUGIN_URL . 'assets/admin/js/packing.js', array(), MPCF_VERSION );
		wp_enqueue_script_module( 'mpcf-shipment', MPCF_PLUGIN_URL . 'assets/admin/js/shipment.js', array(), MPCF_VERSION );
		wp_enqueue_script_module( 'mpcf-documents', MPCF_PLUGIN_URL . 'assets/admin/js/documents.js', array(), MPCF_VERSION );
		wp_enqueue_script_module( 'mpcf-photos', MPCF_PLUGIN_URL . 'assets/admin/js/photos.js', array(), MPCF_VERSION );
		wp_enqueue_script_module( 'mpcf-scan', MPCF_PLUGIN_URL . 'assets/admin/js/scan.js', array(), MPCF_VERSION );
		wp_enqueue_script_module( 'mpcf-shortcuts', MPCF_PLUGIN_URL . 'assets/admin/js/shortcuts.js', array(), MPCF_VERSION );

		// Script modules have no `wp_add_inline_script()` counterpart —
		// this small config object is set as an ordinary global via a
		// classic, no-dependency inline script instead, printed in the
		// footer alongside the module tags themselves; `api.js` reads it
		// off `window.mpcfWorkspace` at call time, not at its own
		// module-evaluation time, so it does not matter that this runs as
		// a separate, unrelated script rather than truly "before" a
		// specific module the way `wp_add_inline_script()`'s `before`
		// position means for a classic script.
		$settings = new Settings();

		wp_add_inline_script(
			'mpcf-mpds-scan-sink',
			sprintf(
				'window.mpcfWorkspace = { restUrl: %s, nonce: %s, photos: { required: %s, maxPerFulfillment: %d, maxUploadBytes: %d, canCapture: %s, canDelete: %s } };',
				wp_json_encode( rest_url( 'mpcf/v1/' ) ),
				wp_json_encode( wp_create_nonce( 'wp_rest' ) ),
				$settings->photos_required() ? 'true' : 'false',
				$settings->photos_max_per_fulfillment(),
				$settings->photos_max_upload_bytes(),
				current_user_can( Capabilities::CAPTURE_PHOTOS ) ? 'true' : 'false',
				current_user_can( Capabilities::DELETE_PHOTOS ) ? 'true' : 'false'
			)
		);
	}

	/**
	 * Enqueues CS Detail gallery preview helpers (protected REST thumbs).
	 */
	private function enqueue_detail_photo_assets(): void {
		wp_enqueue_script( 'wp-api-request' );
		wp_add_inline_script(
			'wp-api-request',
			sprintf(
				'window.mpcfWorkspace = window.mpcfWorkspace || { restUrl: %s, nonce: %s };',
				wp_json_encode( rest_url( 'mpcf/v1/' ) ),
				wp_json_encode( wp_create_nonce( 'wp_rest' ) )
			),
			'before'
		);
		wp_enqueue_script(
			'mpcf-detail-photos',
			MPCF_PLUGIN_URL . 'assets/admin/js/detail-photos.js',
			array(),
			MPCF_VERSION,
			true
		);
	}

	/**
	 * Whether the current admin request is the Settings screen.
	 */
	private static function is_settings_screen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection, no state change.

		return 'mpcf-settings' === $page;
	}

	/**
	 * Whether the current admin request is Fulfillment Detail.
	 */
	private static function is_detail_screen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection, no state change.

		return 'mpcf-fulfillment-detail' === $page;
	}

	/**
	 * Whether the current admin request is the Documents history screen.
	 */
	private static function is_documents_screen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection, no state change.

		return 'mpcf-documents' === $page;
	}

	/**
	 * Whether the current admin request is specifically the Packing
	 * Workspace — the only screen whose markup needs the action-bar/
	 * scan-sink/toast behavior modules {@see enqueue_workspace_assets()}
	 * loads.
	 */
	private static function is_workspace_screen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection, no state change.

		return 'mpcf-workspace' === $page;
	}

	/**
	 * Whether the current admin request is the Wave Workspace.
	 */
	private static function is_wave_screen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection, no state change.

		return 'mpcf-wave' === $page;
	}

	/**
	 * Whether the current admin request is the Queue.
	 */
	private static function is_queue_screen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection, no state change.

		return 'mpcf-queue' === $page;
	}

	/**
	 * Enqueues Wave Workspace modules + scan sink.
	 */
	private function enqueue_wave_assets(): void {
		wp_enqueue_script( 'mpcf-mpds-toast', MPCF_PLUGIN_URL . 'assets/mpds/js/toast.js', array(), MPCF_VERSION, true );
		wp_enqueue_script( 'mpcf-mpds-scan-sink', MPCF_PLUGIN_URL . 'assets/mpds/js/scan-sink.js', array(), MPCF_VERSION, true );
		wp_enqueue_script_module( 'mpcf-wave', MPCF_PLUGIN_URL . 'assets/admin/js/wave.js', array(), MPCF_VERSION );

		// Same localization pattern as the Packing Workspace: attach the
		// REST config to the classic scan-sink handle. Script modules have
		// no wp_add_inline_script() counterpart; api.js reads the global
		// at call time.
		wp_add_inline_script(
			'mpcf-mpds-scan-sink',
			sprintf(
				'window.mpcfWorkspace = window.mpcfWorkspace || { restUrl: %s, nonce: %s };',
				wp_json_encode( rest_url( 'mpcf/v1/' ) ),
				wp_json_encode( wp_create_nonce( 'wp_rest' ) )
			)
		);
	}

	/**
	 * Enqueues Queue "create wave" helper.
	 */
	private function enqueue_queue_wave_assets(): void {
		wp_enqueue_script_module( 'mpcf-queue-wave', MPCF_PLUGIN_URL . 'assets/admin/js/queue-wave.js', array(), MPCF_VERSION );
		wp_enqueue_script( 'wp-api-request' );
		wp_add_inline_script(
			'wp-api-request',
			sprintf(
				'window.mpcfWorkspace = window.mpcfWorkspace || { restUrl: %s, nonce: %s }; window.mpcfWavePage = %s;',
				wp_json_encode( rest_url( 'mpcf/v1/' ) ),
				wp_json_encode( wp_create_nonce( 'wp_rest' ) ),
				wp_json_encode( admin_url( 'admin.php?page=' . WavePage::SLUG ) )
			),
			'before'
		);
	}

	/**
	 * Adds the token-scope root class to `<body>` on this plugin's screens,
	 * so `tokens.css` custom properties apply outside the page wrap too.
	 *
	 * @param string $classes Space-separated existing body classes.
	 */
	public function maybe_add_body_class( string $classes ): string {
		if ( ! self::is_plugin_screen() ) {
			return $classes;
		}

		return $classes . ' mpcf-ui-scope mpcf-admin';
	}

	/**
	 * Whether the current admin request is for one of this plugin's screens.
	 */
	private static function is_plugin_screen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection, no state change.

		return in_array( $page, self::SCREEN_SLUGS, true );
	}
}
