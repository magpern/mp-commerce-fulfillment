<?php
/**
 * Unit tests for the settings value type.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use MPCF\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Settings value-type tests.
 */
final class SettingsTest extends TestCase {

	protected function setUp(): void {
		mpcf_tests_reset_wp_state();
	}

	public function test_defaults_keep_data_on_uninstall(): void {
		$defaults = Settings::defaults();

		self::assertFalse( $defaults['remove_data_on_uninstall'] );
		self::assertSame( Settings::SCHEMA_VERSION, $defaults['schema_version'] );
	}

	public function test_defaults_match_the_approved_bridge_behavior_defaults(): void {
		$defaults = Settings::defaults();

		self::assertTrue( $defaults['outbound_bridge_enabled'], 'The outbound bridge defaults on, per architecture decision P2.' );
		self::assertSame( Settings::BRIDGE_BEHAVIOR_CANCEL, $defaults['inbound_cancel_behavior'], 'Cancellation defaults to automatic.' );
		self::assertSame( Settings::BRIDGE_BEHAVIOR_FLAG, $defaults['inbound_refund_behavior'], 'A full refund defaults to flagged for review.' );
	}

	public function test_sanitize_accepts_explicit_valid_bridge_behavior_values(): void {
		$sanitized = Settings::sanitize(
			array(
				'outbound_bridge_enabled' => false,
				'inbound_cancel_behavior' => Settings::BRIDGE_BEHAVIOR_FLAG,
				'inbound_refund_behavior' => Settings::BRIDGE_BEHAVIOR_CANCEL,
			)
		);

		self::assertFalse( $sanitized['outbound_bridge_enabled'] );
		self::assertSame( Settings::BRIDGE_BEHAVIOR_FLAG, $sanitized['inbound_cancel_behavior'] );
		self::assertSame( Settings::BRIDGE_BEHAVIOR_CANCEL, $sanitized['inbound_refund_behavior'] );
	}

	public function test_sanitize_falls_back_to_the_safe_default_for_an_unrecognized_behavior_value(): void {
		$sanitized = Settings::sanitize(
			array(
				'inbound_cancel_behavior' => 'delete_everything',
				'inbound_refund_behavior' => array( 'not', 'a', 'string' ),
			)
		);

		self::assertSame( Settings::BRIDGE_BEHAVIOR_CANCEL, $sanitized['inbound_cancel_behavior'] );
		self::assertSame( Settings::BRIDGE_BEHAVIOR_FLAG, $sanitized['inbound_refund_behavior'] );
	}

	public function test_sanitize_upgrades_a_pre_bridge_settings_array_while_preserving_its_own_values(): void {
		// The exact shape a real install running the pre-D14 code would have
		// persisted: schema_version 1, none of the bridge keys present yet,
		// and an explicit non-default choice already made for the one key
		// that did exist. Sanitizing it must fill in the new keys with their
		// defaults and must not lose the pre-existing choice.
		$legacy = array(
			'schema_version'           => 1,
			'remove_data_on_uninstall' => true,
		);

		$sanitized = Settings::sanitize( $legacy );

		self::assertSame( Settings::SCHEMA_VERSION, $sanitized['schema_version'], 'Sanitizing always normalizes to the current schema version.' );
		self::assertTrue( $sanitized['remove_data_on_uninstall'], 'A pre-existing explicit choice must survive the upgrade.' );
		self::assertTrue( $sanitized['outbound_bridge_enabled'] );
		self::assertSame( Settings::BRIDGE_BEHAVIOR_CANCEL, $sanitized['inbound_cancel_behavior'] );
		self::assertSame( Settings::BRIDGE_BEHAVIOR_FLAG, $sanitized['inbound_refund_behavior'] );
	}

	public function test_accessors_read_the_bridge_settings(): void {
		$settings = new Settings(
			array(
				'outbound_bridge_enabled' => false,
				'inbound_cancel_behavior' => Settings::BRIDGE_BEHAVIOR_FLAG,
				'inbound_refund_behavior' => Settings::BRIDGE_BEHAVIOR_CANCEL,
			)
		);

		self::assertFalse( $settings->outbound_bridge_enabled() );
		self::assertSame( Settings::BRIDGE_BEHAVIOR_FLAG, $settings->inbound_cancel_behavior() );
		self::assertSame( Settings::BRIDGE_BEHAVIOR_CANCEL, $settings->inbound_refund_behavior() );
	}

	public function test_defaults_keep_operator_mode_off(): void {
		self::assertFalse( Settings::defaults()['operator_mode_enabled'] );
	}

	public function test_sanitize_coerces_operator_mode_truthy_and_falsy_values(): void {
		self::assertTrue( Settings::sanitize( array( 'operator_mode_enabled' => '1' ) )['operator_mode_enabled'] );
		self::assertTrue( Settings::sanitize( array( 'operator_mode_enabled' => true ) )['operator_mode_enabled'] );
		self::assertFalse( Settings::sanitize( array( 'operator_mode_enabled' => 0 ) )['operator_mode_enabled'] );
		self::assertFalse( Settings::sanitize( array() )['operator_mode_enabled'] );
	}

	public function test_accessor_reads_operator_mode(): void {
		$settings = new Settings( array( 'operator_mode_enabled' => true ) );

		self::assertTrue( $settings->operator_mode_enabled() );
	}

	public function test_defaults_keep_the_workspace_keys_off(): void {
		$defaults = Settings::defaults();

		self::assertFalse( $defaults['auto_advance_after_ship'] );
		self::assertSame( '', $defaults['default_carrier_id'] );
		self::assertFalse( $defaults['require_tracking_before_ship'] );
		self::assertSame( 5, Settings::SCHEMA_VERSION );
	}

	public function test_sanitize_coerces_auto_advance_after_ship_truthy_and_falsy_values(): void {
		self::assertTrue( Settings::sanitize( array( 'auto_advance_after_ship' => '1' ) )['auto_advance_after_ship'] );
		self::assertTrue( Settings::sanitize( array( 'auto_advance_after_ship' => true ) )['auto_advance_after_ship'] );
		self::assertFalse( Settings::sanitize( array( 'auto_advance_after_ship' => 0 ) )['auto_advance_after_ship'] );
		self::assertFalse( Settings::sanitize( array() )['auto_advance_after_ship'] );
	}

	public function test_sanitize_coerces_require_tracking_before_ship_truthy_and_falsy_values(): void {
		self::assertTrue( Settings::sanitize( array( 'require_tracking_before_ship' => '1' ) )['require_tracking_before_ship'] );
		self::assertTrue( Settings::sanitize( array( 'require_tracking_before_ship' => true ) )['require_tracking_before_ship'] );
		self::assertFalse( Settings::sanitize( array( 'require_tracking_before_ship' => 0 ) )['require_tracking_before_ship'] );
		self::assertFalse( Settings::sanitize( array() )['require_tracking_before_ship'] );
	}

	public function test_sanitize_coerces_default_carrier_id_to_a_string_with_no_whitelist(): void {
		// Any string is a valid carrier id (Domain\CarrierRegistry's own
		// contract) — Settings has no dependency on CarrierRegistry and
		// must not validate against it (purity, see class docblock).
		self::assertSame( 'dhl', Settings::sanitize( array( 'default_carrier_id' => 'dhl' ) )['default_carrier_id'] );
		self::assertSame( 'not-a-bundled-carrier', Settings::sanitize( array( 'default_carrier_id' => 'not-a-bundled-carrier' ) )['default_carrier_id'] );
		self::assertSame( '', Settings::sanitize( array() )['default_carrier_id'] );
	}

	public function test_accessors_read_the_workspace_settings(): void {
		$settings = new Settings(
			array(
				'auto_advance_after_ship'      => true,
				'default_carrier_id'           => 'postnord',
				'require_tracking_before_ship' => true,
			)
		);

		self::assertTrue( $settings->auto_advance_after_ship() );
		self::assertSame( 'postnord', $settings->default_carrier_id() );
		self::assertTrue( $settings->require_tracking_before_ship() );
	}

	public function test_sanitize_upgrades_a_pre_workspace_settings_array_while_preserving_its_own_values(): void {
		$legacy = array(
			'schema_version'        => 3,
			'operator_mode_enabled' => true,
		);

		$sanitized = Settings::sanitize( $legacy );

		self::assertSame( Settings::SCHEMA_VERSION, $sanitized['schema_version'] );
		self::assertTrue( $sanitized['operator_mode_enabled'], 'A pre-existing explicit choice must survive the upgrade.' );
		self::assertFalse( $sanitized['auto_advance_after_ship'] );
		self::assertSame( '', $sanitized['default_carrier_id'] );
		self::assertFalse( $sanitized['require_tracking_before_ship'] );
	}

	public function test_sanitize_coerces_invalid_input_instead_of_throwing(): void {
		$sanitized = Settings::sanitize( 'not an array' );

		self::assertSame( Settings::defaults(), $sanitized );
	}

	public function test_sanitize_coerces_truthy_and_falsy_flag_values(): void {
		self::assertTrue( Settings::sanitize( array( 'remove_data_on_uninstall' => '1' ) )['remove_data_on_uninstall'] );
		self::assertTrue( Settings::sanitize( array( 'remove_data_on_uninstall' => true ) )['remove_data_on_uninstall'] );
		self::assertFalse( Settings::sanitize( array( 'remove_data_on_uninstall' => 0 ) )['remove_data_on_uninstall'] );
		self::assertFalse( Settings::sanitize( array() )['remove_data_on_uninstall'] );
	}

	public function test_in_memory_constructor_avoids_get_option(): void {
		$settings = new Settings( array( 'remove_data_on_uninstall' => true ) );

		self::assertTrue( $settings->remove_data_on_uninstall() );
	}

	public function test_save_sanitizes_before_persisting(): void {
		$settings = new Settings( array() );

		$settings->save( array( 'remove_data_on_uninstall' => 'yes' ) );

		self::assertTrue( $settings->remove_data_on_uninstall() );
	}

	public function test_get_loads_lazily_when_constructed_without_data(): void {
		$settings = new Settings();

		self::assertSame( Settings::defaults(), $settings->get() );
	}
}
