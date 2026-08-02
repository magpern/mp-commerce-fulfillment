<?php
/**
 * Integration tests for Operator Mode's wp-admin chrome reduction.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Admin;

use MPCF\Admin\OperatorMode;
use MPCF\Capabilities;
use MPCF\Settings;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use WP_UnitTestCase;

/**
 * Operator Mode is decided entirely by capability, never a role-name
 * string (see the class's own docblock) — these tests prove that against
 * the real roles {@see \MPCF\Capabilities::activate()} creates.
 */
final class OperatorModeTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;

	protected function setUp(): void {
		parent::setUp();
		// See CapabilityMatrixTest's setUp() for why Plugin::activate()
		// needs this first.
		$this->clean_fulfillment_tables();
		\MPCF\Plugin::activate();
	}

	public function test_operator_gets_the_chrome_hiding_class_when_enabled(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$mode = new OperatorMode( new Settings( array( 'operator_mode_enabled' => true ) ) );

		self::assertStringContainsString( 'mpcf-operator-mode', $mode->maybe_add_body_class( '' ) );
	}

	public function test_no_class_is_added_when_the_setting_is_disabled(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$mode = new OperatorMode( new Settings( array( 'operator_mode_enabled' => false ) ) );

		self::assertStringNotContainsString( 'mpcf-operator-mode', $mode->maybe_add_body_class( '' ) );
	}

	public function test_a_lead_never_gets_the_chrome_hiding_class(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$mode = new OperatorMode( new Settings( array( 'operator_mode_enabled' => true ) ) );

		self::assertStringNotContainsString( 'mpcf-operator-mode', $mode->maybe_add_body_class( '' ) );
	}

	public function test_an_administrator_always_keeps_full_chrome(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$mode = new OperatorMode( new Settings( array( 'operator_mode_enabled' => true ) ) );

		self::assertStringNotContainsString( 'mpcf-operator-mode', $mode->maybe_add_body_class( '' ) );
	}

	public function test_operator_mode_defaults_off(): void {
		self::assertFalse( ( new Settings() )->operator_mode_enabled() );
	}
}
