<?php
/**
 * PhotoRetentionEligibility unit tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Media;

use DateTimeImmutable;
use DateTimeZone;
use MPCF\Domain\Media\PhotoKind;
use MPCF\Domain\Media\PhotoRecord;
use MPCF\Domain\Media\PhotoRetentionEligibility;
use PHPUnit\Framework\TestCase;

/**
 * Pure eligibility: retention 0, before/at/after cutoff, already purged.
 */
final class PhotoRetentionEligibilityTest extends TestCase {

	public function test_retention_zero_never_yields_cutoff(): void {
		$now = new DateTimeImmutable( '2026-08-06 12:00:00', new DateTimeZone( 'UTC' ) );

		self::assertNull( PhotoRetentionEligibility::cutoff( 0, $now ) );
	}

	public function test_cutoff_subtracts_months_in_utc(): void {
		$now    = new DateTimeImmutable( '2026-08-06 12:00:00', new DateTimeZone( 'UTC' ) );
		$cutoff = PhotoRetentionEligibility::cutoff( 12, $now );

		self::assertNotNull( $cutoff );
		self::assertSame( '2025-08-06 12:00:00', $cutoff->format( 'Y-m-d H:i:s' ) );
	}

	public function test_eligibility_at_and_after_cutoff(): void {
		$cutoff = new DateTimeImmutable( '2025-08-06 12:00:00', new DateTimeZone( 'UTC' ) );
		$at     = $this->photo( '2025-08-06 12:00:00' );
		$before = $this->photo( '2025-08-06 11:59:59' );
		$after  = $this->photo( '2025-08-06 12:00:01' );

		self::assertTrue( PhotoRetentionEligibility::is_eligible( $at, $cutoff ) );
		self::assertTrue( PhotoRetentionEligibility::is_eligible( $before, $cutoff ) );
		self::assertFalse( PhotoRetentionEligibility::is_eligible( $after, $cutoff ) );
	}

	public function test_already_purged_and_null_cutoff_are_ineligible(): void {
		$cutoff            = new DateTimeImmutable( '2025-08-06 12:00:00', new DateTimeZone( 'UTC' ) );
		$base              = $this->photo( '2024-01-01 00:00:00' )->to_array();
		$base['purged_at'] = new DateTimeImmutable( '2026-01-01 00:00:00', new DateTimeZone( 'UTC' ) );
		$purged            = PhotoRecord::from_array( $base );

		self::assertFalse( PhotoRetentionEligibility::is_eligible( $purged, $cutoff ) );
		self::assertFalse( PhotoRetentionEligibility::is_eligible( $this->photo( '2024-01-01 00:00:00' ), null ) );
	}

	/**
	 * @param string $created_at UTC datetime string.
	 */
	private function photo( string $created_at ): PhotoRecord {
		return PhotoRecord::from_array(
			array(
				'id'                 => 1,
				'fulfillment_id'     => 10,
				'package_id'         => 20,
				'kind'               => PhotoKind::PACKAGE,
				'file_path'          => 'mpcf/photos/2024/01/10/a.jpg',
				'thumb_path'         => 'mpcf/photos/2024/01/10/a-thumb.jpg',
				'mime'               => 'image/jpeg',
				'bytes'              => 100,
				'sha256'             => str_repeat( 'ab', 32 ),
				'processing_version' => 1,
				'width'              => 10,
				'height'             => 10,
				'seq'                => 1,
				'captured_by'        => null,
				'created_at'         => new DateTimeImmutable( $created_at, new DateTimeZone( 'UTC' ) ),
				'deleted_at'         => null,
				'purged_at'          => null,
			)
		);
	}
}
