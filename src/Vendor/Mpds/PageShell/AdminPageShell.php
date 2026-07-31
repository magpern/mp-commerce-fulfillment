<?php
/**
 * Renders the standalone-menu admin page shell chrome.
 *
 * @package MpAdminDesignSystem
 */

declare(strict_types=1);

namespace MPCF\Vendor\Mpds\PageShell;

use MPCF\Vendor\Mpds\PageShell\ViewModel\AdminPageShellViewModel;

/**
 * Outputs the branded header card, icon navigation and section card chrome.
 *
 * Generalized from Universal Geo Context's `Admin\AdminPageShell` — the
 * standalone top-level-menu shell (as opposed to UMC's `WC_Settings_Page`
 * shell, which stays behind in UMC because it is coupled to that base
 * class's `output_sections()`/`output()` contract). Renamed to the
 * mpcf-ui- prefix; the previously hardcoded `esc_html_e`/`esc_attr__` save
 * button strings now come from the view model.
 */
final class AdminPageShell {

	/**
	 * Builds the shell renderer.
	 *
	 * @param SectionNavigation $navigation Icon navigation renderer.
	 */
	public function __construct(
		private readonly SectionNavigation $navigation
	) {
	}

	/**
	 * Opens the admin page wrap and WordPress notice anchor.
	 *
	 * Core common.js moves global admin notices after `.wp-header-end`, or
	 * otherwise after the first `.wrap h1`/`.wrap h2` — which would land
	 * them inside the branded shell header without this anchor.
	 */
	public function open_wrap(): void {
		echo '<div class="wrap">';
		echo '<hr class="wp-header-end">';
	}

	/**
	 * Closes the admin page wrap opened by {@see open_wrap()}.
	 */
	public function close_wrap(): void {
		echo '</div>';
	}

	/**
	 * Opens the page shell wrapper (also the token scope root — pair with a
	 * consumer's `admin_body_class` filter adding the same class to `<body>`
	 * so tokens.css applies).
	 */
	public function open(): void {
		echo '<div class="mpcf-ui-scope">';
	}

	/**
	 * Closes the page shell wrapper.
	 */
	public function close(): void {
		echo '</div>';
	}

	/**
	 * Opens the readable content layout region.
	 *
	 * @param bool $wide When true, use wide layout without max-width.
	 */
	public function open_content( bool $wide = false ): void {
		$class = $wide ? 'mpcf-ui-layout--wide' : 'mpcf-ui-layout--readable';
		printf( '<div class="%s">', esc_attr( $class ) );
	}

	/**
	 * Closes the content layout region.
	 */
	public function close_content(): void {
		echo '</div>';
	}

	/**
	 * Renders the page shell header above page content.
	 *
	 * @param AdminPageShellViewModel $view_model Shell presentation data.
	 * @param callable|null           $actions    Optional callback that echoes contextual actions.
	 */
	public function render_header( AdminPageShellViewModel $view_model, ?callable $actions = null ): void {
		?>
		<header class="mpcf-ui-shell-header">
			<div class="mpcf-ui-shell-header__main">
				<div class="mpcf-ui-shell-header__brand">
					<div class="mpcf-ui-shell-header__mark" aria-hidden="true">
						<span class="mpcf-ui-shell-header__mark-icon dashicons <?php echo esc_attr( $view_model->mark_icon_class ); ?>"></span>
					</div>
					<div class="mpcf-ui-shell-header__titles">
						<h2 class="mpcf-ui-shell-header__title"><?php echo esc_html( $view_model->plugin_title ); ?></h2>
						<p class="mpcf-ui-shell-header__subtitle"><?php echo esc_html( $view_model->subtitle ); ?></p>
					</div>
				</div>

				<?php $this->navigation->render( $view_model ); ?>

				<?php if ( null !== $actions ) : ?>
					<div class="mpcf-ui-shell-header__actions">
						<?php $actions(); ?>
					</div>
				<?php elseif ( $view_model->has_header_save ) : ?>
					<div class="mpcf-ui-shell-header__actions">
						<button type="submit" name="save" value="<?php echo esc_attr( $view_model->save_label ); ?>" class="button button-primary mpcf-ui-shell-header__save">
							<?php echo esc_html( $view_model->save_label ); ?>
						</button>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( '' !== $view_model->notice_html ) : ?>
				<div class="mpcf-ui-shell-header__notice">
					<?php echo $view_model->notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped by the consumer. ?>
				</div>
			<?php endif; ?>
		</header>
		<?php
	}

	/**
	 * Opens the active section content card.
	 *
	 * @param string $title       Section title.
	 * @param string $description Section description.
	 * @param string $icon_class  Dashicon class.
	 */
	public function open_section_card( string $title, string $description = '', string $icon_class = 'dashicons-admin-generic' ): void {
		$description_html = '' !== $description
			? sprintf( '<p class="mpcf-ui-section-card__description">%s</p>', esc_html( $description ) )
			: '';
		?>
		<div class="mpcf-ui-section-card">
			<header class="mpcf-ui-section-card__header">
				<div class="mpcf-ui-section-card__icon-wrap" aria-hidden="true">
					<span class="mpcf-ui-section-card__icon dashicons <?php echo esc_attr( $icon_class ); ?>"></span>
				</div>
				<div class="mpcf-ui-section-card__heading">
					<h2 class="mpcf-ui-section-card__title"><?php echo esc_html( $title ); ?></h2>
					<?php echo $description_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>
				</div>
			</header>
			<div class="mpcf-ui-section-card__body">
		<?php
	}

	/**
	 * Closes the active section content card.
	 */
	public function close_section_card(): void {
		?>
			</div>
		</div>
		<?php
	}
}
