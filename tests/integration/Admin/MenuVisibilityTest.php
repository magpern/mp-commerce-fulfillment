<?php
/**
 * Real-admin-menu validation: fires the actual `admin_menu` hook
 * `Plugin::wire_admin()` registers against, against a real WordPress
 * install, and inspects what WordPress itself decided to show.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Admin;

use MPCF\Admin\DashboardPage;
use MPCF\Admin\FulfillmentDetailPage;
use MPCF\Admin\QueuePage;
use MPCF\Capabilities;
use MPCF\Plugin;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use WP_UnitTestCase;

/**
 * This is the closest equivalent to a real browser walkthrough available
 * in this headless environment: `set_current_screen()` + firing the real
 * `admin_menu` action populates WordPress's own `$menu`/`$submenu` globals
 * exactly as a real wp-admin page load would, under real capability checks
 * for a real Warehouse Operator/Lead/Administrator account — not a mock of
 * WordPress's menu system.
 */
final class MenuVisibilityTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();
		Plugin::activate();

		// A fresh Plugin instance per test: init() is idempotent-by-instance
		// (booted flag), and the real singleton may already be booted by an
		// earlier test in this process with is_admin() false.
		$reflection = new \ReflectionClass( Plugin::class );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	/**
	 * Registers the real Fulfillment menu against WordPress's own admin
	 * menu globals, as the given user. Resets every global
	 * `add_menu_page()`/`add_submenu_page()` write to, not only `$menu`/
	 * `$submenu` — `$_registered_pages`/`$_parent_pages`/
	 * `$_wp_real_parent_file` accumulate across every call in the same
	 * PHP process with no WP-provided per-request reset (that reset
	 * normally happens once per real admin page load, which never fully
	 * runs here), so a test exercising `user_can_access_admin_page()`
	 * later in the suite than this method's very first call would
	 * otherwise resolve a parent from a stale prior registration.
	 */
	private function register_menu_as( int $user_id ): void {
		wp_set_current_user( $user_id );
		set_current_screen( 'dashboard' );

		global $menu, $submenu, $parent_file, $typenow, $pagenow, $plugin_page, $_registered_pages, $_parent_pages, $_wp_real_parent_file, $_wp_menu_nopriv, $_wp_submenu_nopriv;
		$menu                 = array();
		$submenu              = array();
		$parent_file          = '';
		$typenow              = '';
		$pagenow              = '';
		$plugin_page          = null;
		$_registered_pages    = array();
		$_parent_pages        = array();
		$_wp_real_parent_file = array();
		$_wp_menu_nopriv      = array();
		$_wp_submenu_nopriv   = array();

		Plugin::instance()->init();
		do_action( 'admin_menu' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	public function test_operator_sees_the_fulfillment_menu_with_dashboard_and_queue_only(): void {
		$this->register_menu_as( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		global $submenu;

		self::assertArrayHasKey( DashboardPage::SLUG, $submenu, 'The Fulfillment top-level menu must be registered.' );

		$slugs = array_column( $submenu[ DashboardPage::SLUG ], 2 );

		self::assertContains( DashboardPage::SLUG, $slugs );
		self::assertContains( QueuePage::SLUG, $slugs );

		// Fulfillment Detail's entry is deliberately still present in
		// $submenu — Plugin.php no longer calls remove_submenu_page(),
		// because doing so breaks get_admin_page_parent()'s ability to
		// resolve this page's parent, which breaks
		// user_can_access_admin_page() for every user including
		// administrators (see test_fulfillment_detail_page_is_directly_
		// reachable_despite_being_hidden() below — the real, load-bearing
		// proof). "Never a visible nav item" is now enforced by CSS only;
		// see test_hidden_pages_are_css_hidden_not_removed_from_submenu().
		self::assertContains( FulfillmentDetailPage::SLUG, $slugs );
	}

	public function test_hidden_pages_are_css_hidden_not_removed_from_submenu(): void {
		$this->register_menu_as( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		ob_start();
		do_action( 'admin_head' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$head = (string) ob_get_clean();

		self::assertStringContainsString( 'page=' . FulfillmentDetailPage::SLUG, $head, 'admin_head must emit CSS hiding the Fulfillment Detail submenu link.' );
		self::assertStringContainsString( 'display:none', $head );
	}

	public function test_lead_sees_the_same_two_visible_menu_items_as_an_operator(): void {
		$this->register_menu_as( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		global $submenu;

		$slugs = array_column( $submenu[ DashboardPage::SLUG ], 2 );

		self::assertContains( DashboardPage::SLUG, $slugs );
		self::assertContains( QueuePage::SLUG, $slugs );
	}

	public function test_administrator_sees_the_fulfillment_menu_too(): void {
		$this->register_menu_as( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $submenu;

		self::assertArrayHasKey( DashboardPage::SLUG, $submenu );
	}

	public function test_a_user_with_no_mpcf_capability_never_sees_the_fulfillment_menu(): void {
		// add_menu_page() registers into the raw $menu global unconditionally
		// — WordPress's own later wp-admin/menu.php rendering pass is what
		// actually strips entries the current user can't access, based on
		// each entry's registered capability. That later pass isn't
		// something a plugin can fire standalone in a test, so the
		// equivalent, direct proof is that the capability it would check is
		// false for this user — exactly the gate add_menu_page() was given.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		self::assertFalse( current_user_can( Capabilities::VIEW_QUEUE ), 'A subscriber must never hold the capability the Fulfillment menu is gated on.' );
	}

	/**
	 * The real regression this class exists to prevent a repeat of
	 * (found by the Playwright suite's first real HTTP request against
	 * this page, F22 — no PHPUnit test had ever exercised
	 * `user_can_access_admin_page()`, the exact function `wp-admin/
	 * includes/menu.php` calls before dispatching any page, for a real
	 * direct-URL request against a hidden page). The previous version of
	 * this test only asserted `menu_page_url()` returns a non-empty
	 * string, which stays true regardless of whether the page is actually
	 * *reachable* — `menu_page_url()` only consults `$_parent_pages`,
	 * never `user_can_access_admin_page()`'s own `$_registered_pages`
	 * hookname lookup, so it could not have caught the real defect either.
	 */
	public function test_fulfillment_detail_page_is_directly_reachable_despite_being_hidden(): void {
		$this->register_menu_as( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		global $pagenow, $plugin_page;
		$pagenow     = 'admin.php';
		$plugin_page = FulfillmentDetailPage::SLUG;

		self::assertTrue( user_can_access_admin_page(), 'A direct admin.php?page=mpcf-fulfillment-detail request must resolve as accessible for a Warehouse Operator, exactly as wp-admin/includes/menu.php checks before dispatching any page.' );
	}

	public function test_workspace_page_is_directly_reachable_despite_being_hidden(): void {
		$this->register_menu_as( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		global $pagenow, $plugin_page;
		$pagenow     = 'admin.php';
		$plugin_page = \MPCF\Admin\WorkspacePage::SLUG;

		self::assertTrue( user_can_access_admin_page(), 'A direct admin.php?page=mpcf-workspace request must resolve as accessible for a Warehouse Operator.' );
	}
}
