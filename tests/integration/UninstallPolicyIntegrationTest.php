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

	use CleanFulfillmentTablesTrait;

	private const UNINSTALL_FILE = __DIR__ . '/../../uninstall.php';

	protected function setUp(): void {
		parent::setUp();
		// This class's own "remove_data_on_uninstall=true" test really does
		// DROP every table this plugin owns — a DDL statement, so it is
		// never undone by WP_UnitTestCase's per-test rollback the way the
		// option/role changes around it are. See the trait's docblock: that
		// mismatch can leave mpcf_db_version claiming a schema state that no
		// longer matches physical reality for whichever test runs next.
		$this->clean_fulfillment_tables();
	}

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

	public function test_uninstall_does_not_unschedule_action_scheduler_work_when_the_flag_is_disabled(): void {
		Plugin::activate();
		update_option( Settings::OPTION, Settings::sanitize( array( 'remove_data_on_uninstall' => false ) ) );

		as_enqueue_async_action( 'mpcf_process_intake', array( 'order_id' => 1 ), 'mpcf' );
		self::assertNotFalse( as_next_scheduled_action( 'mpcf_process_intake', array( 'order_id' => 1 ), 'mpcf' ) );

		include self::UNINSTALL_FILE;

		self::assertNotFalse(
			as_next_scheduled_action( 'mpcf_process_intake', array( 'order_id' => 1 ), 'mpcf' ),
			'A disabled uninstall must not unschedule outstanding Action Scheduler work.'
		);
	}

	public function test_uninstall_unschedules_the_entire_mpcf_action_scheduler_group_when_enabled(): void {
		Plugin::activate();
		update_option( Settings::OPTION, Settings::sanitize( array( 'remove_data_on_uninstall' => true ) ) );

		as_enqueue_async_action( 'mpcf_process_intake', array( 'order_id' => 1 ), 'mpcf' );
		as_enqueue_async_action( 'mpcf_process_intake', array( 'order_id' => 2 ), 'mpcf' );

		include self::UNINSTALL_FILE;

		// as_unschedule_all_actions() cancels rather than deletes: the row
		// survives with a "canceled" status, so as_get_scheduled_actions()
		// would still list it. "Removed" here means no longer scheduled to
		// run — the same pending-only view as_next_scheduled_action() gives.
		self::assertFalse( as_next_scheduled_action( 'mpcf_process_intake', array( 'order_id' => 1 ), 'mpcf' ) );
		self::assertFalse( as_next_scheduled_action( 'mpcf_process_intake', array( 'order_id' => 2 ), 'mpcf' ) );
	}

	public function test_uninstall_never_touches_a_scheduled_action_outside_its_own_group(): void {
		Plugin::activate();
		update_option( Settings::OPTION, Settings::sanitize( array( 'remove_data_on_uninstall' => true ) ) );

		as_enqueue_async_action( 'some_other_plugins_action', array(), 'not-mpcf' );

		include self::UNINSTALL_FILE;

		self::assertNotFalse(
			as_next_scheduled_action( 'some_other_plugins_action', array(), 'not-mpcf' ),
			'Uninstall must never unschedule another group\'s Action Scheduler work.'
		);
	}

	public function test_uninstall_cleanup_is_idempotent_when_run_twice(): void {
		Plugin::activate();
		update_option( Settings::OPTION, Settings::sanitize( array( 'remove_data_on_uninstall' => true ) ) );

		as_enqueue_async_action( 'mpcf_process_intake', array( 'order_id' => 1 ), 'mpcf' );

		include self::UNINSTALL_FILE;
		include self::UNINSTALL_FILE;

		foreach ( PersistedKeys::option_keys() as $option ) {
			self::assertFalse( get_option( $option, false ) );
		}

		self::assertFalse( as_next_scheduled_action( 'mpcf_process_intake', array( 'order_id' => 1 ), 'mpcf' ) );
	}
}
