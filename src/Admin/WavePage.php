<?php
/**
 * Wave Workspace admin screen.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

use MPCF\Capabilities;
use MPCF\Vendor\Mpds\ComponentRenderer;
use MPCF\Vendor\Mpds\PageShell\AdminPageShell;
use MPCF\Vendor\Mpds\PageShell\Page;

/**
 * Architecture Plan Part X.5 — dedicated Wave Workspace (hidden submenu).
 * Mutations go through `mpcf/v1/waves…` via wave.js.
 */
final class WavePage implements Page {

	/**
	 * This page's slug.
	 */
	public const SLUG = 'mpcf-wave';

	/**
	 * Page-shell chrome renderer.
	 *
	 * @var AdminPageShell
	 */
	private AdminPageShell $shell;

	/**
	 * MPDS component renderer (scan sink).
	 *
	 * @var ComponentRenderer
	 */
	private ComponentRenderer $renderer;

	/**
	 * Builds the page.
	 *
	 * @param AdminPageShell    $shell    Page shell.
	 * @param ComponentRenderer $renderer MPDS component renderer.
	 */
	public function __construct( AdminPageShell $shell, ComponentRenderer $renderer ) {
		$this->shell    = $shell;
		$this->renderer = $renderer;
	}

	/**
	 * Page slug.
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * Capability.
	 */
	public function capability(): string {
		return Capabilities::VIEW_QUEUE;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Wave Workspace', 'mp-commerce-fulfillment' );
	}

	/**
	 * Menu title (hidden via CSS).
	 */
	public function menu_title(): string {
		return __( 'Wave', 'mp-commerce-fulfillment' );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'mp-commerce-fulfillment' ) );
		}

		$wave_id = isset( $_GET['wave_id'] ) ? absint( wp_unslash( $_GET['wave_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only deep link.

		$this->shell->open_wrap();
		$this->shell->open();
		$this->shell->render_header( ShellHeader::view_model( QueuePage::SLUG ) );
		$this->shell->open_content( true );

		echo '<div class="mpcf-wave-workspace" data-mpcf-wave-workspace';
		if ( $wave_id > 0 ) {
			printf( ' data-mpcf-wave-id="%d"', (int) $wave_id );
		}
		echo '>';

		echo '<h1>' . esc_html__( 'Wave Workspace', 'mp-commerce-fulfillment' ) . '</h1>';

		if ( $wave_id <= 0 ) {
			echo '<p>' . esc_html__( 'Open a wave from the Queue, or create one from selected fulfillments.', 'mp-commerce-fulfillment' ) . '</p>';
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=' . QueuePage::SLUG ) ),
				esc_html__( 'Back to Queue', 'mp-commerce-fulfillment' )
			);
			echo '</div>';
			$this->shell->close_content();
			$this->shell->close();
			$this->shell->close_wrap();

			return;
		}

		echo '<div class="mpcf-wave-status" data-mpcf-wave-status></div>';
		echo '<div class="mpcf-wave-progress" data-mpcf-wave-progress></div>';
		echo '<div class="mpcf-wave-actions" data-mpcf-wave-actions>';
		echo '<button type="button" class="button" data-mpcf-wave-activate>' . esc_html__( 'Activate', 'mp-commerce-fulfillment' ) . '</button> ';
		echo '<button type="button" class="button" data-mpcf-wave-pause>' . esc_html__( 'Pause', 'mp-commerce-fulfillment' ) . '</button> ';
		echo '<button type="button" class="button" data-mpcf-wave-resume>' . esc_html__( 'Resume', 'mp-commerce-fulfillment' ) . '</button> ';
		echo '<button type="button" class="button" data-mpcf-wave-complete>' . esc_html__( 'Complete', 'mp-commerce-fulfillment' ) . '</button> ';
		echo '<button type="button" class="button" data-mpcf-wave-abandon>' . esc_html__( 'Abandon', 'mp-commerce-fulfillment' ) . '</button> ';
		echo '<button type="button" class="button button-primary" data-mpcf-wave-print>' . esc_html__( 'Print picking list', 'mp-commerce-fulfillment' ) . '</button> ';
		echo '<button type="button" class="button button-primary" data-mpcf-wave-enter-scan>' . esc_html__( 'Enter Wave Scan Mode', 'mp-commerce-fulfillment' ) . '</button>';
		echo '</div>';

		echo '<div class="mpcf-wave-scan" data-mpcf-wave-scan hidden>';
		echo '<p data-mpcf-wave-scan-status></p>';
		echo '<p data-mpcf-wave-scan-result></p>';
		echo '<p data-mpcf-wave-scan-progress></p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ComponentRenderer::scan_input() returns escaped markup.
		echo $this->renderer->scan_input( 'wave_scan', array( 'aria-label' => __( 'Wave barcode scanner input', 'mp-commerce-fulfillment' ) ) );
		echo '<button type="button" class="button" data-mpcf-wave-scan-undo>' . esc_html__( 'Undo last scan', 'mp-commerce-fulfillment' ) . '</button> ';
		echo '<button type="button" class="button" data-mpcf-wave-exit-scan>' . esc_html__( 'Exit Scan Mode', 'mp-commerce-fulfillment' ) . '</button>';
		echo '</div>';

		echo '<div class="mpcf-wave-exceptions" data-mpcf-wave-exceptions></div>';
		echo '<div class="mpcf-wave-walk" data-mpcf-wave-walk></div>';
		echo '<div class="mpcf-wave-members" data-mpcf-wave-members></div>';

		echo '</div>';

		$this->shell->close_content();
		$this->shell->close();
		$this->shell->close_wrap();
	}
}
