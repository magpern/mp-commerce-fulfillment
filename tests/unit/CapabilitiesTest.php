<?php
/**
 * Unit tests for capability and role lifecycle.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use MPCF\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * Capability/role activation and uninstall tests.
 */
final class CapabilitiesTest extends TestCase {

	protected function setUp(): void {
		mpcf_tests_reset_wp_state();
	}

	public function test_activate_creates_both_custom_roles_with_the_documented_split(): void {
		Capabilities::activate();

		$operator = get_role( Capabilities::ROLE_OPERATOR );
		$lead     = get_role( Capabilities::ROLE_LEAD );

		self::assertNotNull( $operator );
		self::assertNotNull( $lead );

		self::assertTrue( $operator->has_cap( Capabilities::VIEW_QUEUE ) );
		self::assertTrue( $operator->has_cap( Capabilities::PROCESS_FULFILLMENTS ) );
		self::assertFalse( $operator->has_cap( Capabilities::CANCEL_FULFILLMENT ), 'Operators must not be able to cancel a fulfillment.' );
		self::assertFalse( $operator->has_cap( Capabilities::DELETE_PHOTOS ), 'Operators must not soft-delete photos (Lead+ only).' );
		self::assertFalse( $operator->has_cap( Capabilities::MANAGE_SETTINGS ), 'Operators must not manage settings.' );

		foreach ( Capabilities::all() as $capability ) {
			self::assertTrue( $lead->has_cap( $capability ), "Warehouse Lead must hold {$capability}." );
		}
	}

	public function test_activate_grants_every_capability_to_existing_administrator_and_shop_manager_roles(): void {
		add_role( 'administrator', 'Administrator' );
		add_role( 'shop_manager', 'Shop Manager' );

		Capabilities::activate();

		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );

			foreach ( Capabilities::all() as $capability ) {
				self::assertTrue( $role->has_cap( $capability ), "{$role_name} must hold {$capability}." );
			}
		}
	}

	public function test_activate_does_not_touch_roles_that_do_not_exist(): void {
		// No 'administrator'/'shop_manager' seeded — activate() must not fatal
		// when those roles are absent (e.g. a WooCommerce-less test harness).
		Capabilities::activate();

		self::assertNull( get_role( 'administrator' ) );
	}

	public function test_activate_is_idempotent(): void {
		Capabilities::activate();
		Capabilities::activate();

		$operator = get_role( Capabilities::ROLE_OPERATOR );

		self::assertNotNull( $operator );
		self::assertTrue( $operator->has_cap( Capabilities::VIEW_QUEUE ) );
	}

	public function test_uninstall_removes_both_custom_roles(): void {
		Capabilities::activate();
		Capabilities::uninstall();

		self::assertNull( get_role( Capabilities::ROLE_OPERATOR ) );
		self::assertNull( get_role( Capabilities::ROLE_LEAD ) );
	}

	public function test_uninstall_strips_every_capability_from_every_role_that_holds_one(): void {
		add_role( 'administrator', 'Administrator' );

		Capabilities::activate();
		Capabilities::uninstall();

		$administrator = get_role( 'administrator' );

		self::assertNotNull( $administrator, 'Uninstall must not remove a role it did not create.' );

		foreach ( Capabilities::all() as $capability ) {
			self::assertFalse( $administrator->has_cap( $capability ) );
		}
	}
}
