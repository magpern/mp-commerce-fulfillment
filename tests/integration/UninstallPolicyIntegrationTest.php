<?php
/**
 * Real-WordPress proof of invariant I12 (all-or-nothing uninstall),
 * complementing the unit-level UninstallPolicyGuardTest's in-memory proof.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration;

use MPCF\Capabilities;
use MPCF\Infrastructure\Database\Migrator;
use MPCF\PersistedKeys;
use MPCF\Plugin;
use MPCF\Settings;
use WP_UnitTestCase;

/**
 * Real-WordPress uninstall lifecycle tests.
 */
final class UninstallPolicyIntegrationTest extends WP_UnitTestCase {

	private const UNINSTALL_FILE = __DIR__ . '/../../uninstall.php';

	public function test_deactivate_then_reactivate_loses_nothing(): void {
		Plugin::activate();

		$version_before  = get_option( Migrator::OPTION );
		$operator_before = get_role( Capabilities::ROLE_OPERATOR );
		self::assertNotNull( $operator_before );

		// No deactivation hook exists (BootstrapGuardTest proves this
		// statically); "deactivating" this plugin is therefore a no-op by
		// construction. Reactivating must still leave everything intact.
		Plugin::activate();

		self::assertSame( $version_before, get_option( Migrator::OPTION ) );
		self::assertNotNull( get_role( Capabilities::ROLE_OPERATOR ) );
	}

	public function test_uninstall_is_a_no_op_when_the_flag_is_disabled(): void {
		Plugin::activate();
		update_option( Settings::OPTION, Settings::sanitize( array( 'remove_data_on_uninstall' => false ) ) );

		include self::UNINSTALL_FILE;

		self::assertNotFalse( get_option( Migrator::OPTION, false ) );
		self::assertNotNull( get_role( Capabilities::ROLE_OPERATOR ) );
		self::assertNotNull( get_role( Capabilities::ROLE_LEAD ) );

		$administrator = get_role( 'administrator' );
		self::assertTrue( $administrator->has_cap( Capabilities::VIEW_QUEUE ) );
	}

	public function test_uninstall_removes_exactly_the_persisted_keys_inventory_when_enabled(): void {
		Plugin::activate();
		update_option( Settings::OPTION, Settings::sanitize( array( 'remove_data_on_uninstall' => true ) ) );

		include self::UNINSTALL_FILE;

		foreach ( PersistedKeys::option_keys() as $option ) {
			self::assertFalse( get_option( $option, false ), "{$option} must be removed." );
		}

		foreach ( PersistedKeys::roles() as $role ) {
			self::assertNull( get_role( $role ), "{$role} must be removed." );
		}

		$administrator = get_role( 'administrator' );
		foreach ( PersistedKeys::capabilities() as $capability ) {
			self::assertFalse( $administrator->has_cap( $capability ), "administrator must lose {$capability}." );
		}
	}
}
