<?php
/**
 * Admin page contract for the standalone-menu shell.
 *
 * @package MpAdminDesignSystem
 */

declare(strict_types=1);

namespace MPCF\Vendor\Mpds\PageShell;

/**
 * One registered wp-admin screen under a consumer's top-level menu.
 *
 * Generalized from Universal Geo Context's `Admin\Page` interface — the
 * standalone-menu shell any MP Commerce plugin can register a top-level
 * admin menu against.
 */
interface Page {

	/**
	 * Returns the page slug.
	 */
	public function slug(): string;

	/**
	 * Returns the browser page title.
	 */
	public function title(): string;

	/**
	 * Returns the submenu label.
	 */
	public function menu_title(): string;

	/**
	 * Returns the capability required to view this page.
	 */
	public function capability(): string;

	/**
	 * Renders the page body. Called inside the shell wrap; implementations
	 * are responsible for their own content, not the surrounding chrome.
	 */
	public function render(): void;
}
