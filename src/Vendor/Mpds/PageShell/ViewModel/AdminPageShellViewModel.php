<?php
/**
 * Shell header presentation data.
 *
 * @package MpAdminDesignSystem
 */

declare(strict_types=1);

namespace MPCF\Vendor\Mpds\PageShell\ViewModel;

/**
 * Everything {@see \MPCF\Vendor\Mpds\PageShell\AdminPageShell::render_header()} needs to
 * draw the branded header. Plain public-property DTO (house pattern); every
 * string is already localized and/or escaped by the consumer's factory —
 * this library carries no text domain of its own.
 */
final class AdminPageShellViewModel {

	/**
	 * Builds the shell header presentation data.
	 *
	 * @param string                    $plugin_title    Brand title shown in the header.
	 * @param string                    $subtitle        Supporting subtitle text.
	 * @param string                    $mark_icon_class  Dashicon class for the brand mark.
	 * @param bool                      $has_header_save Whether to show the default save button.
	 * @param string                    $save_label      Already-localized save button label.
	 * @param string                    $notice_html     Optional pre-escaped notice markup.
	 * @param SectionNavItemViewModel[] $navigation_items Icon navigation items.
	 * @param string                    $navigation_aria_label Already-localized nav landmark label.
	 */
	public function __construct(
		public readonly string $plugin_title,
		public readonly string $subtitle,
		public readonly string $mark_icon_class,
		public readonly bool $has_header_save,
		public readonly string $save_label,
		public readonly string $notice_html,
		public readonly array $navigation_items,
		public readonly string $navigation_aria_label
	) {
	}
}
