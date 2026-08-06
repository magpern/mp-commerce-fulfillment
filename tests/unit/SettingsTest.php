<?php
/**
 * Unit tests for the settings value type.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use MPCF\Domain\Notification\NotificationStrategy;
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
		self::assertFalse( $defaults['photos_required'] );
		self::assertSame( 10, $defaults['photos_max_per_fulfillment'] );
		self::assertSame( 12582912, $defaults['photos_max_upload_bytes'] );
		self::assertSame( 2000, $defaults['photos_max_edge_px'] );
		self::assertSame( 12, $defaults['photos_retention_months'] );
		self::assertSame( 8, Settings::SCHEMA_VERSION );
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

	public function test_sanitize_coerces_photos_required_truthy_and_falsy_values(): void {
		self::assertTrue( Settings::sanitize( array( 'photos_required' => '1' ) )['photos_required'] );
		self::assertTrue( Settings::sanitize( array( 'photos_required' => true ) )['photos_required'] );
		self::assertFalse( Settings::sanitize( array( 'photos_required' => 0 ) )['photos_required'] );
		self::assertFalse( Settings::sanitize( array() )['photos_required'] );
	}

	public function test_sanitize_clamps_photo_limit_settings(): void {
		$sanitized = Settings::sanitize(
			array(
				'photos_max_per_fulfillment' => 0,
				'photos_max_upload_bytes'    => 100,
				'photos_max_edge_px'         => 50,
				'photos_retention_months'    => 0,
			)
		);

		self::assertSame( 1, $sanitized['photos_max_per_fulfillment'] );
		self::assertSame( 1048576, $sanitized['photos_max_upload_bytes'] );
		self::assertSame( 500, $sanitized['photos_max_edge_px'] );
		self::assertSame( 0, $sanitized['photos_retention_months'] );

		$high = Settings::sanitize(
			array(
				'photos_max_per_fulfillment' => 999,
				'photos_max_upload_bytes'    => 999999999,
				'photos_max_edge_px'         => 99999,
				'photos_retention_months'    => 999,
			)
		);

		self::assertSame( 100, $high['photos_max_per_fulfillment'] );
		self::assertSame( 52428800, $high['photos_max_upload_bytes'] );
		self::assertSame( 8000, $high['photos_max_edge_px'] );
		self::assertSame( 120, $high['photos_retention_months'] );
	}

	public function test_photo_limit_accessors_read_sanitized_values(): void {
		$settings = new Settings(
			array(
				'photos_max_per_fulfillment' => 5,
				'photos_max_upload_bytes'    => 2097152,
				'photos_max_edge_px'         => 1500,
				'photos_retention_months'    => 24,
			)
		);

		self::assertSame( 5, $settings->photos_max_per_fulfillment() );
		self::assertSame( 2097152, $settings->photos_max_upload_bytes() );
		self::assertSame( 1500, $settings->photos_max_edge_px() );
		self::assertSame( 24, $settings->photos_retention_months() );
	}

	public function test_sanitize_upgrades_pre_m6c_settings_while_preserving_photos_required(): void {
		$legacy = array(
			'schema_version'  => 7,
			'photos_required' => true,
		);

		$sanitized = Settings::sanitize( $legacy );

		self::assertSame( Settings::SCHEMA_VERSION, $sanitized['schema_version'] );
		self::assertTrue( $sanitized['photos_required'] );
		self::assertSame( 10, $sanitized['photos_max_per_fulfillment'] );
		self::assertSame( 12582912, $sanitized['photos_max_upload_bytes'] );
		self::assertSame( 2000, $sanitized['photos_max_edge_px'] );
		self::assertSame( 12, $sanitized['photos_retention_months'] );
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
				'photos_required'              => true,
			)
		);

		self::assertTrue( $settings->auto_advance_after_ship() );
		self::assertSame( 'postnord', $settings->default_carrier_id() );
		self::assertTrue( $settings->require_tracking_before_ship() );
		self::assertTrue( $settings->photos_required() );
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
		self::assertFalse( $sanitized['photos_required'] );
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

	public function test_defaults_include_notification_configuration(): void {
		$defaults = Settings::defaults();

		self::assertSame( NotificationStrategy::COMPLETED_EMAIL, $defaults['notification_strategy'] );
		self::assertSame( 'Your order has shipped', $defaults['notification_email_subject'] );
		self::assertSame( '', $defaults['notification_sender_name'] );
		self::assertSame( '', $defaults['notification_reply_to'] );
	}

	public function test_sanitize_falls_back_for_invalid_notification_strategy(): void {
		$sanitized = Settings::sanitize( array( 'notification_strategy' => 'EMAIL_EVERYONE' ) );

		self::assertSame( NotificationStrategy::COMPLETED_EMAIL, $sanitized['notification_strategy'] );
	}

	public function test_sanitize_accepts_every_notification_strategy(): void {
		foreach ( NotificationStrategy::values() as $value ) {
			self::assertSame(
				$value,
				Settings::sanitize( array( 'notification_strategy' => $value ) )['notification_strategy']
			);
		}
	}

	public function test_sanitize_clears_invalid_reply_to_email(): void {
		self::assertSame(
			'',
			Settings::sanitize( array( 'notification_reply_to' => 'not-an-email' ) )['notification_reply_to']
		);
		self::assertSame(
			'ops@example.com',
			Settings::sanitize( array( 'notification_reply_to' => 'ops@example.com' ) )['notification_reply_to']
		);
	}

	public function test_sanitize_truncates_notification_text_fields(): void {
		$long      = str_repeat( 'a', 300 );
		$sanitized = Settings::sanitize(
			array(
				'notification_sender_name'   => $long,
				'notification_email_subject' => $long,
			)
		);

		self::assertSame( 191, strlen( $sanitized['notification_sender_name'] ) );
		self::assertSame( 191, strlen( $sanitized['notification_email_subject'] ) );
	}

	public function test_sanitize_upgrades_pre_notification_settings_while_preserving_values(): void {
		$legacy = array(
			'schema_version'       => 5,
			'documents_store_name' => 'Acme',
		);

		$sanitized = Settings::sanitize( $legacy );

		self::assertSame( Settings::SCHEMA_VERSION, $sanitized['schema_version'] );
		self::assertSame( 'Acme', $sanitized['documents_store_name'] );
		self::assertSame( NotificationStrategy::COMPLETED_EMAIL, $sanitized['notification_strategy'] );
	}

	public function test_notification_accessors_read_sanitized_values(): void {
		$settings = new Settings(
			array(
				'notification_strategy'        => NotificationStrategy::BOTH,
				'notification_sender_name'     => 'Desk',
				'notification_reply_to'        => 'desk@example.com',
				'notification_email_subject'   => 'Shipped',
				'notification_tracking_footer' => 'Thanks',
			)
		);

		self::assertSame( NotificationStrategy::BOTH, $settings->notification_strategy() );
		self::assertSame( 'Desk', $settings->notification_sender_name() );
		self::assertSame( 'desk@example.com', $settings->notification_reply_to() );
		self::assertSame( 'Shipped', $settings->notification_email_subject() );
		self::assertSame( 'Thanks', $settings->notification_tracking_footer() );
	}
}
