<?php
/**
 * PhotoConfig factory tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Photos;

use MPCF\Application\Photos\PhotoConfig;
use MPCF\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Settings-backed PhotoConfig construction.
 */
final class PhotoConfigFromSettingsTest extends TestCase {

	public function test_from_settings_reads_max_keys_with_processing_version_one(): void {
		$settings = new Settings(
			array(
				'photos_max_upload_bytes'    => 2097152,
				'photos_max_edge_px'         => 1500,
				'photos_max_per_fulfillment' => 4,
			)
		);

		$config = PhotoConfig::from_settings( $settings );

		self::assertSame( 2097152, $config->max_upload_bytes() );
		self::assertSame( 1500, $config->max_edge_px() );
		self::assertSame( 4, $config->max_photos_per_fulfillment() );
		self::assertSame( 1, $config->processing_version() );
	}
}
