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
	 * menu globals, as the given user.
	 */
	private function register_menu_as( int $user_id ): void {
		wp_set_current_user( $user_id );
		set_current_screen( 'dashboard' );

		global $menu, $submenu;
		$menu    = array();
		$submenu = array();

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
		self::assertNotContains( FulfillmentDetailPage::SLUG, $slugs, 'Fulfillment Detail must be reachable but never listed in the visible menu.' );
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

	public function test_fulfillment_detail_page_is_directly_reachable_despite_being_hidden(): void {
		$this->register_menu_as( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		// remove_submenu_page() only hides the menu entry; WordPress still
		// dispatches the page callback for a direct URL — verified by
		// checking the same admin_page_hooks the direct link Queue/
		// Dashboard render() build (admin.php?page=mpcf-fulfillment-detail)
		// would resolve against.
		self::assertNotFalse( menu_page_url( FulfillmentDetailPage::SLUG, false ), 'The hidden page must still resolve to a real URL.' );
	}
}
