<?php
/**
 * Top-level admin menu registration.
 *
 * @package MpAdminDesignSystem
 */

declare(strict_types=1);

namespace MPCF\Vendor\Mpds\PageShell;

/**
 * Registers a top-level admin menu and its submenu pages.
 *
 * Generalized from Universal Geo Context's `Admin\Menu` — that version
 * constructor-injected each of its own named page classes; this version
 * takes an ordered list of {@see Page} instances instead, since the design
 * system does not know a consumer's specific screens.
 */
final class Menu {

	/**
	 * Ordered pages; the first is also the top-level menu's landing page.
	 *
	 * @var Page[]
	 */
	private array $pages;

	/**
	 * Builds the menu registrar.
	 *
	 * @param string $menu_slug  Slug of the top-level menu (usually the first page's slug).
	 * @param string $menu_title Top-level menu label.
	 * @param string $icon_class Dashicon class or icon URL/base64 for `add_menu_page()`.
	 * @param string $capability Capability required to see the top-level menu.
	 * @param Page[] $pages      Ordered submenu pages; the first is the landing page.
	 */
	public function __construct(
		private readonly string $menu_slug,
		private readonly string $menu_title,
		private readonly string $icon_class,
		private readonly string $capability,
		array $pages
	) {
		$this->pages = array_values( $pages );
	}

	/**
	 * Registers hooks. Callers still register each page's own handlers
	 * (form submits, admin-post actions) separately — this class only wires
	 * the menu structure.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu_pages' ) );
	}

	/**
	 * Registers the top-level menu and every submenu page.
	 */
	public function register_menu_pages(): void {
		if ( array() === $this->pages ) {
			return;
		}

		$landing = $this->pages[0];

		add_menu_page(
			$landing->title(),
			$this->menu_title,
			$this->capability,
			$this->menu_slug,
			array( $landing, 'render' ),
			$this->icon_class,
			null
		);

		foreach ( $this->pages as $page ) {
			add_submenu_page(
				$this->menu_slug,
				$page->title(),
				$page->menu_title(),
				$page->capability(),
				$page->slug(),
				array( $page, 'render' )
			);
		}
	}
}
