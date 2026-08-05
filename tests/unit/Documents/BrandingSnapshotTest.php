<?php
/**
 * Branding snapshot and settings sanitization tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Documents;

use MPCF\Documents\BrandingSnapshot;
use MPCF\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Branding defaults, sanitization, and snapshot immutability.
 */
final class BrandingSnapshotTest extends TestCase {

	protected function setUp(): void {
		mpcf_tests_reset_wp_state();
	}

	public function test_defaults_leave_branding_optional_fields_empty(): void {
		$defaults = Settings::defaults();

		self::assertSame( '', $defaults['documents_store_name'] );
		self::assertSame( '', $defaults['documents_address'] );
		self::assertSame( '', $defaults['documents_footer'] );
		self::assertSame( 0, $defaults['documents_logo_attachment_id'] );
	}

	public function test_sanitize_strips_control_characters_and_caps_length(): void {
		$sanitized = Settings::sanitize(
			array(
				'documents_store_name'         => "Acme\x00 Store",
				'documents_address'            => "Line 1\r\nLine 2",
				'documents_footer'             => str_repeat( 'x', 2500 ),
				'documents_logo_attachment_id' => -5,
			)
		);

		self::assertSame( 'Acme Store', $sanitized['documents_store_name'] );
		self::assertSame( "Line 1\nLine 2", $sanitized['documents_address'] );
		self::assertSame( 2000, strlen( $sanitized['documents_footer'] ) );
		self::assertSame( 0, $sanitized['documents_logo_attachment_id'] );
	}

	public function test_capture_falls_back_to_blog_name_when_store_name_empty(): void {
		$snapshot = BrandingSnapshot::capture( new Settings( array() ), 'Site From WP' );

		self::assertSame( 'Site From WP', $snapshot['store_name'] );
		self::assertSame( array(), $snapshot['address_lines'] );
		self::assertSame( '', $snapshot['footer'] );
		self::assertSame( '', $snapshot['logo_data_uri'] );
	}

	public function test_capture_freezes_configured_branding_values(): void {
		$settings = new Settings(
			array(
				'documents_store_name' => 'Frozen Co',
				'documents_address'    => "A\nB",
				'documents_footer'     => 'Legal',
			)
		);

		$first = BrandingSnapshot::capture( $settings, 'ignored' );

		$settings->save(
			array_merge(
				$settings->get(),
				array(
					'documents_store_name' => 'Changed Later',
					'documents_footer'     => 'New footer',
				)
			)
		);

		self::assertSame( 'Frozen Co', $first['store_name'] );
		self::assertSame( array( 'A', 'B' ), $first['address_lines'] );
		self::assertSame( 'Legal', $first['footer'] );

		$second = BrandingSnapshot::capture( $settings, 'ignored' );
		self::assertSame( 'Changed Later', $second['store_name'] );
	}

	public function test_accessors_read_document_branding_keys(): void {
		$settings = new Settings(
			array(
				'documents_store_name'         => 'Named',
				'documents_address'            => 'Addr',
				'documents_footer'             => 'Foot',
				'documents_logo_attachment_id' => 12,
			)
		);

		self::assertSame( 'Named', $settings->documents_store_name() );
		self::assertSame( 'Addr', $settings->documents_address() );
		self::assertSame( 'Foot', $settings->documents_footer() );
		self::assertSame( 12, $settings->documents_logo_attachment_id() );
	}
}
