<?php
/**
 * The Fulfillment menu's shared shell-header navigation items.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

use MPCF\Vendor\Mpds\PageShell\ViewModel\SectionNavItemViewModel;

/**
 * Fulfillment Detail is deliberately not one of these — it is reached from
 * the Queue/Dashboard, never a standalone nav destination (Architecture
 * Plan Sec9.3), and its own submenu registration is removed from the visible
 * menu right after WordPress registers it ({@see Plugin::wire_admin()}).
 */
final class ShellNavigation {

	/**
	 * Builds the navigation items, marking `$current_slug` active.
	 *
	 * @param string $current_slug The currently displayed page's slug.
	 * @return list<SectionNavItemViewModel>
	 */
	public static function items( string $current_slug ): array {
		return array(
			new SectionNavItemViewModel(
				__( 'Dashboard', 'mp-commerce-fulfillment' ),
				admin_url( 'admin.php?page=' . DashboardPage::SLUG ),
				'dashicons-dashboard',
				DashboardPage::SLUG === $current_slug
			),
			new SectionNavItemViewModel(
				__( 'Queue', 'mp-commerce-fulfillment' ),
				admin_url( 'admin.php?page=' . QueuePage::SLUG ),
				'dashicons-list-view',
				QueuePage::SLUG === $current_slug
			),
		);
	}
}
