<?php
/**
 * Enqueues MPDS and plugin-owned admin assets, scoped to MPCF screens.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

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
	private const SCREEN_SLUGS = array( 'mpcf-dashboard', 'mpcf-queue', 'mpcf-fulfillment-detail', 'mpcf-workspace' );

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

		wp_enqueue_script( 'mpcf-mpds-data-table-keynav', MPCF_PLUGIN_URL . 'assets/mpds/js/data-table-keynav.js', array(), MPCF_VERSION, true );
		wp_enqueue_script( 'mpcf-mpds-drawer', MPCF_PLUGIN_URL . 'assets/mpds/js/drawer.js', array(), MPCF_VERSION, true );
		wp_enqueue_script( 'mpcf-mpds-modal', MPCF_PLUGIN_URL . 'assets/mpds/js/modal.js', array(), MPCF_VERSION, true );

		wp_script_add_data( 'mpcf-mpds-data-table-keynav', 'type', 'module' );
		wp_script_add_data( 'mpcf-mpds-drawer', 'type', 'module' );
		wp_script_add_data( 'mpcf-mpds-modal', 'type', 'module' );

		if ( self::is_workspace_screen() ) {
			$this->enqueue_workspace_assets();
		}
	}

	/**
	 * Enqueues the Packing Workspace's own behavior modules — the vendored
	 * MPDS ones its markup depends on (toast, action-bar, scan-sink) plus
	 * this plugin's own bootstrap — and localizes the small config object
	 * `assets/admin/js/api.js` reads its REST base URL and nonce from.
	 */
	private function enqueue_workspace_assets(): void {
		wp_enqueue_script( 'mpcf-mpds-toast', MPCF_PLUGIN_URL . 'assets/mpds/js/toast.js', array(), MPCF_VERSION, true );
		wp_enqueue_script( 'mpcf-mpds-action-bar', MPCF_PLUGIN_URL . 'assets/mpds/js/action-bar.js', array(), MPCF_VERSION, true );
		wp_enqueue_script( 'mpcf-mpds-scan-sink', MPCF_PLUGIN_URL . 'assets/mpds/js/scan-sink.js', array(), MPCF_VERSION, true );
		wp_enqueue_script( 'mpcf-workspace', MPCF_PLUGIN_URL . 'assets/admin/js/workspace.js', array(), MPCF_VERSION, true );

		wp_script_add_data( 'mpcf-mpds-toast', 'type', 'module' );
		wp_script_add_data( 'mpcf-mpds-action-bar', 'type', 'module' );
		wp_script_add_data( 'mpcf-mpds-scan-sink', 'type', 'module' );
		wp_script_add_data( 'mpcf-workspace', 'type', 'module' );

		wp_add_inline_script(
			'mpcf-workspace',
			sprintf(
				'window.mpcfWorkspace = { restUrl: %s, nonce: %s };',
				wp_json_encode( rest_url( 'mpcf/v1/' ) ),
				wp_json_encode( wp_create_nonce( 'wp_rest' ) )
			),
			'before'
		);
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
