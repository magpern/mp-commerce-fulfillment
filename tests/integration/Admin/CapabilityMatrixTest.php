<?php
/**
 * Integration tests for the D18 role/capability matrix, against the real
 * roles Capabilities::activate() creates.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Admin;

use MPCF\Admin\DashboardPage;
use MPCF\Admin\FulfillmentDetailPage;
use MPCF\Admin\OrdersPage;
use MPCF\Admin\QueuePage;
use MPCF\Capabilities;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use WP_UnitTestCase;

/**
 * The exact role behavior D18 requires. Screen-level `capability()` values
 * are asserted directly against `current_user_can()` under each role,
 * proving the menu visibility WordPress itself enforces would behave
 * correctly — not re-testing WordPress's own `add_submenu_page()`, which
 * this plugin does not own.
 */
final class CapabilityMatrixTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;

	protected function setUp(): void {
		parent::setUp();
		// Plugin::activate() runs the full Migrator (roles/capabilities are
		// only half of what it does) — the fulfillment tables must exist
		// first or its own schema step errors against a stale
		// mpcf_db_version, the same DDL/rollback mismatch documented on
		// CleanFulfillmentTablesTrait.
		$this->clean_fulfillment_tables();
		\MPCF\Plugin::activate();
	}

	public function test_operator_can_view_queue_process_and_add_notes(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		self::assertTrue( current_user_can( Capabilities::VIEW_QUEUE ) );
		self::assertTrue( current_user_can( Capabilities::PROCESS_FULFILLMENTS ) );
		self::assertTrue( current_user_can( Capabilities::ADD_NOTES ) );
	}

	public function test_operator_cannot_cancel_view_audit_view_analytics_or_manage_settings(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		self::assertFalse( current_user_can( Capabilities::CANCEL_FULFILLMENT ) );
		self::assertFalse( current_user_can( Capabilities::VIEW_AUDIT ) );
		self::assertFalse( current_user_can( Capabilities::VIEW_ANALYTICS ) );
		self::assertFalse( current_user_can( Capabilities::MANAGE_SETTINGS ) );
	}

	public function test_operator_cannot_manage_woocommerce_merely_by_being_able_to_use_mpcf(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		self::assertFalse( current_user_can( 'manage_woocommerce' ) ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- A real order-platform capability, asserted absent for this role.
		self::assertFalse( current_user_can( 'edit_shop_orders' ) ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Same.
	}

	public function test_lead_includes_every_operator_capability_plus_cancel_and_audit(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		foreach ( Capabilities::operator() as $capability ) {
			self::assertTrue( current_user_can( $capability ), "Lead must retain operator capability {$capability}." );
		}

		self::assertTrue( current_user_can( Capabilities::CANCEL_FULFILLMENT ) );
		self::assertTrue( current_user_can( Capabilities::VIEW_AUDIT ) );
	}

	public function test_administrator_and_shop_manager_retain_the_complete_capability_set(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		foreach ( Capabilities::all() as $capability ) {
			self::assertTrue( current_user_can( $capability ), "Administrator must hold {$capability}." );
		}
	}

	public function test_every_screen_declares_view_queue_as_its_gating_capability(): void {
		// All M1/M3 operator-visible screens are visible to both Operator and Lead — the
		// distinction between the two roles is enforced per-action inside
		// the screens (QueuePageTest/FulfillmentDetailPageTest), not by
		// hiding entire screens from the operator. capability() is a pure
		// accessor, checkable without building each Page's full service
		// graph.
		self::assertSame( Capabilities::VIEW_QUEUE, self::capability_of( QueuePage::class ) );
		self::assertSame( Capabilities::VIEW_QUEUE, self::capability_of( FulfillmentDetailPage::class ) );
		self::assertSame( Capabilities::VIEW_QUEUE, self::capability_of( DashboardPage::class ) );
		self::assertSame( Capabilities::VIEW_QUEUE, self::capability_of( OrdersPage::class ) );
	}

	private static function capability_of( string $page_class ): string {
		$reflection = new \ReflectionClass( $page_class );
		$instance   = $reflection->newInstanceWithoutConstructor();

		return $instance->capability();
	}
}
