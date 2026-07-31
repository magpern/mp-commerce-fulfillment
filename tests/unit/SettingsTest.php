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

	public function test_defaults_keep_data_on_uninstall(): void {
		$defaults = Settings::defaults();

		self::assertFalse( $defaults['remove_data_on_uninstall'] );
		self::assertSame( Settings::SCHEMA_VERSION, $defaults['schema_version'] );
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
