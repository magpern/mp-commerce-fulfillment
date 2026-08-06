<?php
/**
 * PhotoRecord unit tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Media;

use DateTimeImmutable;
use InvalidArgumentException;
use MPCF\Domain\Media\PhotoKind;
use MPCF\Domain\Media\PhotoRecord;
use PHPUnit\Framework\TestCase;

/**
 * Immutable evidence record validation.
 */
final class PhotoRecordTest extends TestCase {

	public function test_create_and_round_trip(): void {
		$now    = new DateTimeImmutable( '2026-08-06 10:00:00' );
		$sha    = str_repeat( 'a', 64 );
		$record = PhotoRecord::create(
			10,
			20,
			PhotoKind::PACKAGE,
			'mpcf/photos/2026/08/10/token.jpg',
			'mpcf/photos/2026/08/10/token-thumb.jpg',
			'image/jpeg',
			1234,
			$sha,
			1,
			800,
			600,
			1,
			7,
			$now
		);

		self::assertNull( $record->id() );
		self::assertTrue( $record->is_active() );
		self::assertFalse( $record->is_deleted() );

		$rebuilt = PhotoRecord::from_array( array( 'id' => 99 ) + $record->to_array() );

		self::assertSame( 99, $rebuilt->id() );
		self::assertSame( PhotoKind::PACKAGE, $rebuilt->kind() );
		self::assertSame( $sha, $rebuilt->sha256() );
	}

	public function test_rejects_path_traversal(): void {
		$this->expectException( InvalidArgumentException::class );
		PhotoRecord::create(
			1,
			1,
			PhotoKind::CONTENTS,
			'mpcf/photos/../secret.jpg',
			'mpcf/photos/2026/08/1/t-thumb.jpg',
			'image/jpeg',
			10,
			str_repeat( 'b', 64 ),
			1,
			10,
			10,
			1,
			null,
			new DateTimeImmutable()
		);
	}

	public function test_rejects_short_sha256(): void {
		$this->expectException( InvalidArgumentException::class );
		PhotoRecord::create(
			1,
			1,
			PhotoKind::PACKAGE,
			'mpcf/photos/2026/08/1/a.jpg',
			'mpcf/photos/2026/08/1/a-thumb.jpg',
			'image/jpeg',
			10,
			'abc',
			1,
			10,
			10,
			1,
			null,
			new DateTimeImmutable()
		);
	}

	public function test_deleted_flag_from_array(): void {
		$record = PhotoRecord::from_array(
			array(
				'id'                 => 1,
				'fulfillment_id'     => 1,
				'package_id'         => 1,
				'kind'               => PhotoKind::PACKAGE,
				'file_path'          => 'mpcf/photos/2026/08/1/a.jpg',
				'thumb_path'         => 'mpcf/photos/2026/08/1/a-thumb.jpg',
				'mime'               => 'image/jpeg',
				'bytes'              => 10,
				'sha256'             => str_repeat( 'c', 64 ),
				'processing_version' => 1,
				'width'              => 10,
				'height'             => 10,
				'seq'                => 1,
				'captured_by'        => null,
				'created_at'         => new DateTimeImmutable( '2026-08-01' ),
				'deleted_at'         => new DateTimeImmutable( '2026-08-02' ),
				'purged_at'          => null,
			)
		);

		self::assertTrue( $record->is_deleted() );
		self::assertFalse( $record->is_active() );
	}
}
