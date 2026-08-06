<?php
/**
 * Plugin settings admin screen.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

use MPCF\Application\Notifications\NotificationConfigurationService;
use MPCF\Capabilities;
use MPCF\Domain\CarrierRegistry;
use MPCF\Domain\Notification\NotificationStrategy;
use MPCF\Settings;
use MPCF\Vendor\Mpds\ComponentRenderer;
use MPCF\Vendor\Mpds\PageShell\AdminPageShell;
use MPCF\Vendor\Mpds\PageShell\Page;

/**
 * Settings surface: document branding (M4-B), package photography (M6-C),
 * and notifications (M5-B). Uses MPDS settings cards and the existing
 * `mpcf_settings` option.
 */
final class SettingsPage implements Page {

	/**
	 * This page's slug.
	 */
	public const SLUG = 'mpcf-settings';

	/**
	 * Sticky-save scope id for the settings form.
	 */
	private const STICKY_SCOPE = 'settings';

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
	 * Carrier registry (immutable definitions for the default-carrier select).
	 *
	 * @var CarrierRegistry
	 */
	private CarrierRegistry $carriers;

	/**
	 * Validated notification configuration (display of effective values).
	 *
	 * @var NotificationConfigurationService
	 */
	private NotificationConfigurationService $notification_config;

	/**
	 * Whether the last request saved successfully.
	 *
	 * @var bool
	 */
	private bool $saved = false;

	/**
	 * Builds the page.
	 *
	 * @param AdminPageShell                   $shell               Page-shell chrome renderer.
	 * @param ComponentRenderer                $renderer            MPDS component renderer.
	 * @param Settings                         $settings            Plugin settings.
	 * @param CarrierRegistry                  $carriers            Carrier registry.
	 * @param NotificationConfigurationService $notification_config Notification configuration.
	 */
	public function __construct(
		AdminPageShell $shell,
		ComponentRenderer $renderer,
		Settings $settings,
		CarrierRegistry $carriers,
		NotificationConfigurationService $notification_config
	) {
		$this->shell               = $shell;
		$this->renderer            = $renderer;
		$this->settings            = $settings;
		$this->carriers            = $carriers;
		$this->notification_config = $notification_config;
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

		$data   = $this->settings->get();
		$config = $this->notification_config->get();

		$this->shell->open_wrap();
		$this->shell->open();
		$this->shell->render_header( ShellHeader::view_model( self::SLUG ) );
		$this->shell->open_content( true );

		if ( $this->saved ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Settings saved.', 'mp-commerce-fulfillment' )
			);
		}

		printf(
			'<form method="post" action="" data-mpcf-sticky-root="%s">',
			esc_attr( self::STICKY_SCOPE )
		);
		wp_nonce_field( 'mpcf_save_settings', 'mpcf_settings_nonce' );

		$this->render_documents_card( $data );
		$this->render_photography_card( $data );
		$this->render_notifications_card( $data, $config->default_carrier_id() );

		echo $this->renderer->sticky_save_bar( self::STICKY_SCOPE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS sticky_save_bar escapes.

		echo '</form>';

		$this->shell->close_content();
		$this->shell->close();
		$this->shell->close_wrap();
	}

	/**
	 * Document branding card.
	 *
	 * @param array<string, mixed> $data Current settings.
	 */
	private function render_documents_card( array $data ): void {
		$card_open = $this->renderer->settings_card_open(
			__( 'Document branding', 'mp-commerce-fulfillment' ),
			__( 'Shown on packing slips and picking lists. Empty optional fields are omitted. Historical renders keep the branding captured at print time.', 'mp-commerce-fulfillment' )
		);
		echo $card_open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS settings_card_open escapes.

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

		echo $this->renderer->settings_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS helper returns static markup.
	}

	/**
	 * Package photography settings card (M6-C). Retention months are
	 * stored for M6-D; this card does not offer a purge-now action.
	 *
	 * @param array<string, mixed> $data Current settings.
	 */
	private function render_photography_card( array $data ): void {
		$card_open = $this->renderer->settings_card_open(
			__( 'Package photography', 'mp-commerce-fulfillment' ),
			__( 'Evidence photos captured in the packing workspace. Retention is configured here; automatic purge arrives in a later release.', 'mp-commerce-fulfillment' )
		);
		echo $card_open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS settings_card_open escapes.

		$required = $this->renderer->toggle_row(
			'photos_required',
			! empty( $data['photos_required'] ),
			__( 'Require sealed-package photo', 'mp-commerce-fulfillment' ),
			__( 'When enabled, a sealed-package photo is required before packing can be marked packed. Contents photos remain optional evidence.', 'mp-commerce-fulfillment' )
		);
		echo $required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS toggle_row escapes.

		$max_photos = $this->renderer->number_row(
			'photos_max_per_fulfillment',
			__( 'Maximum photos per fulfillment', 'mp-commerce-fulfillment' ),
			(string) (int) $data['photos_max_per_fulfillment'],
			__( 'Active photos across all packages for one fulfillment (1–100).', 'mp-commerce-fulfillment' ),
			array(
				'min'  => '1',
				'max'  => '100',
				'step' => '1',
			)
		);
		echo $max_photos; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS number_row escapes.

		$max_mb = (int) round( (int) $data['photos_max_upload_bytes'] / ( 1024 * 1024 ) );
		if ( $max_mb < 1 ) {
			$max_mb = 1;
		}

		$max_upload = $this->renderer->number_row(
			'photos_max_upload_mb',
			__( 'Maximum upload size (MB)', 'mp-commerce-fulfillment' ),
			(string) $max_mb,
			__( 'Raw upload size before processing (1–50 MiB). Stored as bytes.', 'mp-commerce-fulfillment' ),
			array(
				'min'  => '1',
				'max'  => '50',
				'step' => '1',
			)
		);
		echo $max_upload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS number_row escapes.

		$max_edge = $this->renderer->number_row(
			'photos_max_edge_px',
			__( 'Maximum image edge (px)', 'mp-commerce-fulfillment' ),
			(string) (int) $data['photos_max_edge_px'],
			__( 'Longest edge of the stored JPEG after resize (500–8000).', 'mp-commerce-fulfillment' ),
			array(
				'min'  => '500',
				'max'  => '8000',
				'step' => '1',
			)
		);
		echo $max_edge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS number_row escapes.

		$retention = $this->renderer->number_row(
			'photos_retention_months',
			__( 'Retention (months)', 'mp-commerce-fulfillment' ),
			(string) (int) $data['photos_retention_months'],
			__( 'How long soft-deleted photos are kept before an automatic purge (1–120). Purge itself is not run from this screen.', 'mp-commerce-fulfillment' ),
			array(
				'min'  => '1',
				'max'  => '120',
				'step' => '1',
			)
		);
		echo $retention; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS number_row escapes.

		echo $this->renderer->settings_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS helper returns static markup.
	}

	/**
	 * Notifications configuration card (M5-B — configuration only).
	 *
	 * @param array<string, mixed> $data                    Current settings.
	 * @param string               $effective_default_carrier Registry-resolved default carrier.
	 */
	private function render_notifications_card( array $data, string $effective_default_carrier ): void {
		$card_open = $this->renderer->settings_card_open(
			__( 'Notifications', 'mp-commerce-fulfillment' ),
			__( 'Configure how customers will learn about shipment tracking. Saving these settings does not send email — delivery starts in a later release.', 'mp-commerce-fulfillment' )
		);
		echo $card_open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS settings_card_open escapes.

		$strategy_select = $this->build_strategy_select( (string) $data['notification_strategy'] );
		$strategy_row    = $this->renderer->select_row(
			'notification_strategy',
			__( 'Notification strategy', 'mp-commerce-fulfillment' ),
			__( 'Choose whether tracking appears on the store completed-order email, a dedicated shipped email, both, or neither.', 'mp-commerce-fulfillment' ),
			$strategy_select
		);
		echo $strategy_row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS select_row; options escaped below.

		$carrier_select = $this->build_carrier_select( $effective_default_carrier );
		$carrier_row    = $this->renderer->select_row(
			'default_carrier_id',
			__( 'Default carrier', 'mp-commerce-fulfillment' ),
			__( 'Pre-selected in the packing workspace. Unknown values fall back to Other. Carrier definitions themselves cannot be edited here.', 'mp-commerce-fulfillment' ),
			$carrier_select
		);
		echo $carrier_row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS select_row; options escaped below.

		$sender = $this->renderer->input_row(
			'notification_sender_name',
			__( 'Sender name', 'mp-commerce-fulfillment' ),
			(string) $data['notification_sender_name'],
			__( 'Optional. Leave blank to use the store name at send time.', 'mp-commerce-fulfillment' )
		);
		echo $sender; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS input_row escapes.

		printf(
			'<div class="mpcf-ui-field-row mpcf-ui-field-row--email"><label class="mpcf-ui-field-row__label" for="notification_reply_to">%1$s</label><span class="mpcf-ui-field-row__description">%2$s</span><div class="mpcf-ui-field-row__control"><input type="email" name="notification_reply_to" id="notification_reply_to" value="%3$s" autocomplete="email" /></div></div>',
			esc_html__( 'Reply-to email', 'mp-commerce-fulfillment' ),
			esc_html__( 'Optional. Invalid addresses are cleared on save.', 'mp-commerce-fulfillment' ),
			esc_attr( (string) $data['notification_reply_to'] )
		);

		$subject = $this->renderer->input_row(
			'notification_email_subject',
			__( 'Default email subject', 'mp-commerce-fulfillment' ),
			(string) $data['notification_email_subject'],
			__( 'Used for the dedicated shipped email when that strategy is enabled.', 'mp-commerce-fulfillment' )
		);
		echo $subject; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS input_row escapes.

		printf(
			'<div class="mpcf-ui-field-row"><label for="notification_email_introduction">%1$s</label><textarea id="notification_email_introduction" name="notification_email_introduction" rows="3" class="large-text">%2$s</textarea><p class="description">%3$s</p></div>',
			esc_html__( 'Email introduction', 'mp-commerce-fulfillment' ),
			esc_textarea( (string) $data['notification_email_introduction'] ),
			esc_html__( 'Optional paragraph shown above tracking details.', 'mp-commerce-fulfillment' )
		);

		printf(
			'<div class="mpcf-ui-field-row"><label for="notification_tracking_footer">%1$s</label><textarea id="notification_tracking_footer" name="notification_tracking_footer" rows="3" class="large-text">%2$s</textarea><p class="description">%3$s</p></div>',
			esc_html__( 'Tracking message footer', 'mp-commerce-fulfillment' ),
			esc_textarea( (string) $data['notification_tracking_footer'] ),
			esc_html__( 'Optional text under tracking links.', 'mp-commerce-fulfillment' )
		);

		printf(
			'<div class="mpcf-ui-field-row"><label for="notification_email_signature">%1$s</label><textarea id="notification_email_signature" name="notification_email_signature" rows="3" class="large-text">%2$s</textarea><p class="description">%3$s</p></div>',
			esc_html__( 'Email signature', 'mp-commerce-fulfillment' ),
			esc_textarea( (string) $data['notification_email_signature'] ),
			esc_html__( 'Optional closing signature.', 'mp-commerce-fulfillment' )
		);

		echo $this->renderer->settings_card_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MPDS helper returns static markup.
	}

	/**
	 * Builds the notification strategy `<select>` markup.
	 *
	 * @param string $current Current strategy value.
	 */
	private function build_strategy_select( string $current ): string {
		$labels = array(
			NotificationStrategy::COMPLETED_EMAIL => __( 'Completed-order email', 'mp-commerce-fulfillment' ),
			NotificationStrategy::MPCF_SHIPPED    => __( 'MPCF shipped email', 'mp-commerce-fulfillment' ),
			NotificationStrategy::BOTH            => __( 'Both', 'mp-commerce-fulfillment' ),
			NotificationStrategy::DISABLED        => __( 'Disabled', 'mp-commerce-fulfillment' ),
		);

		$options = '';
		foreach ( $labels as $value => $label ) {
			$options .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		return sprintf(
			'<select name="notification_strategy" id="notification_strategy">%s</select>',
			$options
		);
	}

	/**
	 * Builds the default carrier `<select>` markup from the immutable registry.
	 *
	 * @param string $current Effective (registry-valid) carrier id.
	 */
	private function build_carrier_select( string $current ): string {
		$options = '';
		foreach ( $this->carriers->all() as $carrier ) {
			$options .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $carrier['id'] ),
				selected( $current, (string) $carrier['id'], false ),
				esc_html( (string) $carrier['label'] )
			);
		}

		return sprintf(
			'<select name="default_carrier_id" id="default_carrier_id">%s</select>',
			$options
		);
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

		$current['notification_strategy']           = isset( $_POST['notification_strategy'] ) ? wp_unslash( (string) $_POST['notification_strategy'] ) : NotificationStrategy::COMPLETED_EMAIL; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::save().
		$current['default_carrier_id']              = isset( $_POST['default_carrier_id'] ) ? wp_unslash( (string) $_POST['default_carrier_id'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::save(); registry fallback in NotificationConfigurationService.
		$current['notification_sender_name']        = isset( $_POST['notification_sender_name'] ) ? wp_unslash( (string) $_POST['notification_sender_name'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::save().
		$current['notification_reply_to']           = isset( $_POST['notification_reply_to'] ) ? wp_unslash( (string) $_POST['notification_reply_to'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::save().
		$current['notification_email_subject']      = isset( $_POST['notification_email_subject'] ) ? wp_unslash( (string) $_POST['notification_email_subject'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::save().
		$current['notification_email_introduction'] = isset( $_POST['notification_email_introduction'] ) ? wp_unslash( (string) $_POST['notification_email_introduction'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::save().
		$current['notification_tracking_footer']    = isset( $_POST['notification_tracking_footer'] ) ? wp_unslash( (string) $_POST['notification_tracking_footer'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::save().
		$current['notification_email_signature']    = isset( $_POST['notification_email_signature'] ) ? wp_unslash( (string) $_POST['notification_email_signature'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::save().

		$current['photos_required']            = ! empty( $_POST['photos_required'] );
		$current['photos_max_per_fulfillment'] = isset( $_POST['photos_max_per_fulfillment'] ) ? wp_unslash( (string) $_POST['photos_max_per_fulfillment'] ) : '10'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::save().
		$current['photos_max_edge_px']         = isset( $_POST['photos_max_edge_px'] ) ? wp_unslash( (string) $_POST['photos_max_edge_px'] ) : '2000'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::save().
		$current['photos_retention_months']    = isset( $_POST['photos_retention_months'] ) ? wp_unslash( (string) $_POST['photos_retention_months'] ) : '12'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::save().

		$upload_mb = isset( $_POST['photos_max_upload_mb'] ) ? (int) wp_unslash( $_POST['photos_max_upload_mb'] ) : 12; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Coerced to int; sanitized via Settings::save() bytes clamp.
		if ( $upload_mb < 1 ) {
			$upload_mb = 1;
		}
		$current['photos_max_upload_bytes'] = $upload_mb * 1024 * 1024;

		$this->settings->save( $current );
		$this->saved = true;
	}
}
