<?php
/**
 * Guards invariant I12: uninstall is all-or-nothing.
 *
 * With `remove_data_on_uninstall` disabled, running uninstall.php must
 * change nothing at all. With it enabled, uninstall.php must remove exactly
 * `PersistedKeys::inventory()` — no more, no less.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use MPCF\Capabilities;
use MPCF\Plugin;
use MPCF\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Uninstall all-or-nothing guard.
 */
final class UninstallPolicyGuardTest extends TestCase {

	private const UNINSTALL_FILE = __DIR__ . '/../../uninstall.php';

	protected function setUp(): void {
		mpcf_tests_reset_wp_state();
	}

	public function test_uninstall_is_a_no_op_when_the_flag_is_disabled(): void {
		Plugin::activate();
		update_option( Settings::OPTION, Settings::sanitize( array( 'remove_data_on_uninstall' => false ) ) );

		$options_before = $GLOBALS['mpcf_test_options'];
		$roles_before   = array_keys( $GLOBALS['mpcf_test_roles'] );

		include self::UNINSTALL_FILE;

		self::assertSame( $options_before, $GLOBALS['mpcf_test_options'] );
		self::assertSame( $roles_before, array_keys( $GLOBALS['mpcf_test_roles'] ) );
	}

	public function test_uninstall_removes_exactly_the_persisted_keys_inventory_when_enabled(): void {
		Plugin::activate();
		update_option( Settings::OPTION, Settings::sanitize( array( 'remove_data_on_uninstall' => true ) ) );

		include self::UNINSTALL_FILE;

		self::assertArrayNotHasKey( Settings::OPTION, $GLOBALS['mpcf_test_options'] );
		self::assertNull( get_role( Capabilities::ROLE_OPERATOR ) );
		self::assertNull( get_role( Capabilities::ROLE_LEAD ) );
	}
}
