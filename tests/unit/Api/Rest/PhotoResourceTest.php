<?php
/**
 * Unit tests for PhotosController::photo_resource wire shape.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Api\Rest;

use DateTimeImmutable;
use MPCF\Api\Rest\PhotosController;
use MPCF\Domain\Media\PhotoKind;
use MPCF\Domain\Media\PhotoRecord;
use PHPUnit\Framework\TestCase;

/**
 * Confirms the REST photo resource never exposes storage paths.
 */
final class PhotoResourceTest extends TestCase {

	public function test_photo_resource_excludes_storage_paths_and_exposes_stream_routes(): void {
		$photo = PhotoRecord::from_array(
			array(
				'id'                 => 42,
				'fulfillment_id'     => 7,
				'package_id'         => 3,
				'kind'               => PhotoKind::PACKAGE,
				'file_path'          => 'mpcf/photos/2026/08/7/secret.jpg',
				'thumb_path'         => 'mpcf/photos/2026/08/7/secret-thumb.jpg',
				'mime'               => 'image/jpeg',
				'bytes'              => 1234,
				'sha256'             => str_repeat( 'a', 64 ),
				'processing_version' => 1,
				'width'              => 800,
				'height'             => 600,
				'seq'                => 2,
				'captured_by'        => 9,
				'created_at'         => new DateTimeImmutable( '2026-08-06T10:00:00+00:00' ),
				'deleted_at'         => null,
				'purged_at'          => null,
			)
		);

		$resource = PhotosController::photo_resource( $photo );

		self::assertSame( 42, $resource['id'] );
		self::assertSame( 7, $resource['fulfillment_id'] );
		self::assertSame( 3, $resource['package_id'] );
		self::assertSame( PhotoKind::PACKAGE, $resource['kind'] );
		self::assertSame( 2, $resource['sequence'] );
		self::assertSame( '/mpcf/v1/photos/42/content', $resource['content'] );
		self::assertSame( '/mpcf/v1/photos/42/thumb', $resource['thumbnail'] );
		self::assertFalse( $resource['purged'] );
		self::assertTrue( $resource['has_bytes'] );
		self::assertArrayNotHasKey( 'file_path', $resource );
		self::assertArrayNotHasKey( 'thumb_path', $resource );

		foreach ( $resource as $value ) {
			if ( is_string( $value ) ) {
				self::assertStringNotContainsString( 'mpcf/photos/', $value );
			}
		}
	}
}
