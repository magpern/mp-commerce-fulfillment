<?php
/**
 * Renders the shell header's icon navigation.
 *
 * @package MpAdminDesignSystem
 */

declare(strict_types=1);

namespace MPCF\Vendor\Mpds\PageShell;

use MPCF\Vendor\Mpds\PageShell\ViewModel\AdminPageShellViewModel;
use MPCF\Vendor\Mpds\PageShell\ViewModel\SectionNavItemViewModel;

/**
 * Outputs the `mpcf-ui-shell-nav` icon navigation.
 *
 * Generalized from Universal Geo Context's `Admin\SectionNavigation` —
 * unchanged in structure, renamed to the mpcf-ui- prefix, and the
 * previously hardcoded `__()` aria-label now comes from the view model
 * (this library has no text domain of its own).
 */
final class SectionNavigation {

	/**
	 * Renders the navigation landmark.
	 *
	 * @param AdminPageShellViewModel $view_model Shell presentation data.
	 */
	public function render( AdminPageShellViewModel $view_model ): void {
		?>
		<nav class="mpcf-ui-shell-nav" aria-label="<?php echo esc_attr( $view_model->navigation_aria_label ); ?>">
			<ul class="mpcf-ui-shell-nav__list">
				<?php foreach ( $view_model->navigation_items as $item ) : ?>
					<?php $this->render_item( $item ); ?>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
	}

	/**
	 * Renders one navigation item.
	 *
	 * @param SectionNavItemViewModel $item Navigation item view model.
	 */
	private function render_item( SectionNavItemViewModel $item ): void {
		$classes = array( 'mpcf-ui-shell-nav__item' );

		if ( $item->is_active ) {
			$classes[] = 'mpcf-ui-shell-nav__item--active';
		}

		$current = $item->is_active ? ' aria-current="page"' : '';
		?>
		<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
			<a class="mpcf-ui-shell-nav__link" href="<?php echo esc_url( $item->url ); ?>"<?php echo $current; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute name. ?>>
				<span class="mpcf-ui-shell-nav__icon dashicons <?php echo esc_attr( $item->icon_class ); ?>" aria-hidden="true"></span>
				<span class="mpcf-ui-shell-nav__label"><?php echo esc_html( $item->label ); ?></span>
			</a>
		</li>
		<?php
	}
}
