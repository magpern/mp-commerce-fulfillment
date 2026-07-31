<?php
/**
 * Shell navigation item view model.
 *
 * @package MpAdminDesignSystem
 */

declare(strict_types=1);

namespace MPCF\Vendor\Mpds\PageShell\ViewModel;

/**
 * One entry in the shell header's icon navigation. Plain public-property DTO
 * (house pattern) — labels are pre-localized by the consumer's factory.
 */
final class SectionNavItemViewModel {

	/**
	 * Builds one navigation item.
	 *
	 * @param string $label       Already-localized visible label.
	 * @param string $url         Destination URL.
	 * @param string $icon_class  Dashicon class.
	 * @param bool   $is_active   Whether this is the current page.
	 */
	public function __construct(
		public readonly string $label,
		public readonly string $url,
		public readonly string $icon_class,
		public readonly bool $is_active
	) {
	}
}
