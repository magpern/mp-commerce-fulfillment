<?php
/**
 * Plugin settings admin screen (Documents branding for M4-B).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

use MPCF\Capabilities;
use MPCF\Settings;
use MPCF\Vendor\Mpds\ComponentRenderer;
use MPCF\Vendor\Mpds\PageShell\AdminPageShell;
use MPCF\Vendor\Mpds\PageShell\Page;

/**
 * Minimal settings surface for M4-B document branding. Uses MPDS settings
 * cards and the existing `mpcf_settings` option — no new design system.
 */
final class SettingsPage implements Page {

	/**
	 * This page's slug.
	 */
	public const SLUG = 'mpcf-settings';

	/**
	 * Page-shell chrome renderer.
	 *
	 * @var AdminPageShell
	 */
	private AdminPageShell $shell;

	/**
	 * MPDS component renderer.
	 *
	 * @var ComponentRenderer
	 */
	private ComponentRenderer $renderer;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Builds the page.
	 *
	 * @param AdminPageShell    $shell    Page-shell chrome renderer.
	 * @param ComponentRenderer $renderer MPDS component renderer.
	 * @param Settings          $settings Plugin settings.
	 */
	public function __construct( AdminPageShell $shell, ComponentRenderer $renderer, Settings $settings ) {
		$this->shell    = $shell;
		$this->renderer = $renderer;
		$this->settings = $settings;
	}

	/**
	 * This page's slug.
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * The browser page title.
	 */
	public function title(): string {
		return __( 'Fulfillment Settings', 'mp-commerce-fulfillment' );
	}

	/**
	 * The submenu label.
	 */
	public function menu_title(): string {
		return __( 'Settings', 'mp-commerce-fulfillment' );
	}

	/**
	 * The capability required to view this page.
	 */
	public function capability(): string {
		return Capabilities::MANAGE_SETTINGS;
	}

	/**
	 * Renders the page body.
	 */
	public function render(): void {
		$this->maybe_save();

		$data = $this->settings->get();

		$this->shell->open_wrap();
		$this->shell->open();
		$this->shell->render_header( ShellHeader::view_model( self::SLUG ) );
		$this->shell->open_content( true );

		$card_open = $this->renderer->settings_card_open(
			__( 'Document branding', 'mp-commerce-fulfillment' ),
			__( 'Shown on packing slips and picking lists. Empty optional fields are omitted. Historical renders keep the branding captured at print time.', 'mp-commerce-fulfillment' )
		);
		echo $card_open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS settings_card_open escapes.

		echo '<form method="post" action="">';
		wp_nonce_field( 'mpcf_save_settings', 'mpcf_settings_nonce' );

		$input_name = $this->renderer->input_row(
			'documents_store_name',
			__( 'Store display name', 'mp-commerce-fulfillment' ),
			(string) $data['documents_store_name'],
			__( 'Leave blank to use the WordPress site name.', 'mp-commerce-fulfillment' )
		);
		echo $input_name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS input_row escapes.

		printf(
			'<div class="mpcf-ui-field-row"><label for="documents_address">%1$s</label><textarea id="documents_address" name="documents_address" rows="4" class="large-text">%2$s</textarea><p class="description">%3$s</p></div>',
			esc_html__( 'Address', 'mp-commerce-fulfillment' ),
			esc_textarea( (string) $data['documents_address'] ),
			esc_html__( 'One line per row. Optional.', 'mp-commerce-fulfillment' )
		);

		printf(
			'<div class="mpcf-ui-field-row"><label for="documents_footer">%1$s</label><textarea id="documents_footer" name="documents_footer" rows="3" class="large-text">%2$s</textarea><p class="description">%3$s</p></div>',
			esc_html__( 'Footer / legal text', 'mp-commerce-fulfillment' ),
			esc_textarea( (string) $data['documents_footer'] ),
			esc_html__( 'Optional. Printed at the bottom of documents.', 'mp-commerce-fulfillment' )
		);

		$logo_row = $this->renderer->number_row(
			'documents_logo_attachment_id',
			__( 'Logo attachment ID', 'mp-commerce-fulfillment' ),
			(string) (int) $data['documents_logo_attachment_id'],
			__( 'Optional Media Library attachment ID. Embedded into historical HTML as a data URI when the file is a small image; otherwise omitted (never stored as a public URL alone).', 'mp-commerce-fulfillment' ),
			array(
				'min'  => '0',
				'step' => '1',
			)
		);
		echo $logo_row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS number_row escapes.

		$footer = $this->renderer->settings_card_footer(
			'<button type="submit" class="button button-primary">' . esc_html__( 'Save changes', 'mp-commerce-fulfillment' ) . '</button>'
		);
		echo $footer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS settings_card_footer; button label escaped above.

		echo '</form>';

		echo $this->renderer->settings_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS helper returns static markup.

		$this->shell->close_content();
		$this->shell->close();
		$this->shell->close_wrap();
	}

	/**
	 * Handles a POST save when the current user may manage settings.
	 */
	private function maybe_save(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared to literal POST only.

		if ( 'POST' !== $method ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		$nonce = isset( $_POST['mpcf_settings_nonce'] ) ? (string) wp_unslash( $_POST['mpcf_settings_nonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified via wp_verify_nonce.

		if ( ! wp_verify_nonce( $nonce, 'mpcf_save_settings' ) ) {
			return;
		}

		$current = $this->settings->get();

		$current['documents_store_name']         = isset( $_POST['documents_store_name'] ) ? wp_unslash( (string) $_POST['documents_store_name'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::save().
		$current['documents_address']            = isset( $_POST['documents_address'] ) ? wp_unslash( (string) $_POST['documents_address'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::save().
		$current['documents_footer']             = isset( $_POST['documents_footer'] ) ? wp_unslash( (string) $_POST['documents_footer'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::save().
		$current['documents_logo_attachment_id'] = isset( $_POST['documents_logo_attachment_id'] ) ? wp_unslash( (string) $_POST['documents_logo_attachment_id'] ) : '0'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::save().

		$this->settings->save( $current );
	}
}
