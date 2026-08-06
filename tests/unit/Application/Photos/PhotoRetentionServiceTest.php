<?php
/**
 * PhotoRetentionService unit tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Photos;

use DateTimeImmutable;
use DateTimeZone;
use MPCF\Application\EventDispatcher;
use MPCF\Application\Photos\PhotoRetentionService;
use MPCF\Domain\Media\PhotoKind;
use MPCF\Domain\Media\PhotoRecord;
use MPCF\Tests\Unit\Application\Doubles\InMemoryEventRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryMediaRepository;
use MPCF\Tests\Unit\Support\FakePhotoStorage;
use PHPUnit\Framework\TestCase;

/**
 * Retention purge: retention=0, success, missing-file recovery, FS failure, idempotency.
 */
final class PhotoRetentionServiceTest extends TestCase {

	public function test_retention_zero_purges_nothing(): void {
		$ctx = $this->context( 0 );
		$this->seed_old_photo( $ctx );

		$result = $ctx['service']->purge_batch( 10 );

		self::assertSame( 0, $result->examined() );
		self::assertSame( 0, $result->purged() );
		self::assertTrue( $ctx['storage']->has( 'mpcf/photos/2024/01/1/a1.jpg' ) );
	}

	public function test_successful_purge_removes_bytes_marks_purged_and_audits(): void {
		$ctx   = $this->context( 12 );
		$photo = $this->seed_old_photo( $ctx );

		$result = $ctx['service']->purge_batch( 10 );

		self::assertSame( 1, $result->purged() );
		self::assertFalse( $ctx['storage']->has( 'mpcf/photos/2024/01/1/a1.jpg' ) );
		self::assertFalse( $ctx['storage']->has( 'mpcf/photos/2024/01/1/a1-thumb.jpg' ) );

		$fresh = $ctx['media']->get( (int) $photo->id() );
		self::assertNotNull( $fresh );
		self::assertTrue( $fresh->is_purged() );
		self::assertSame( '', $fresh->file_path() );
		self::assertSame( 100, $fresh->bytes() );
		self::assertSame( 64, strlen( $fresh->sha256() ) );

		$events = $ctx['events']->timeline_for_fulfillment( 1 );
		$types  = array_map( static fn( array $e ): string => (string) $e['event_type'], $events );
		self::assertContains( 'photo.purged', $types );

		$purged = null;
		foreach ( $events as $event ) {
			if ( 'photo.purged' === $event['event_type'] ) {
				$purged = $event['payload'];
			}
		}
		self::assertIsArray( $purged );
		self::assertArrayNotHasKey( 'file_path', $purged );
		self::assertArrayNotHasKey( 'thumb_path', $purged );
		self::assertSame( (int) $photo->id(), $purged['photo_id'] );
		self::assertTrue( $purged['canonical_present'] );
	}

	public function test_missing_files_still_mark_purged_idempotently(): void {
		$ctx   = $this->context( 12 );
		$photo = $this->seed_old_photo( $ctx );
		$ctx['storage']->delete_relative( 'mpcf/photos/2024/01/1/a1.jpg' );
		$ctx['storage']->delete_relative( 'mpcf/photos/2024/01/1/a1-thumb.jpg' );

		$result = $ctx['service']->purge_batch( 10 );
		self::assertSame( 1, $result->purged() );
		self::assertTrue( $ctx['media']->get( (int) $photo->id() )->is_purged() );

		$again = $ctx['service']->purge_batch( 10 );
		self::assertSame( 0, $again->examined() );
	}

	public function test_filesystem_failure_does_not_mark_purged(): void {
		$ctx = $this->context( 12 );
		$this->seed_old_photo( $ctx );
		$ctx['storage']->fail_next_delete();

		$result = $ctx['service']->purge_batch( 10 );

		self::assertSame( 1, $result->failed() );
		self::assertSame( 0, $result->purged() );
		$all = $ctx['media']->list_purge_candidates(
			new DateTimeImmutable( '2025-08-06 12:00:00', new DateTimeZone( 'UTC' ) ),
			10
		);
		self::assertCount( 1, $all );
		self::assertFalse( $all[0]->is_purged() );
	}

	public function test_batch_limit_is_bounded(): void {
		$ctx = $this->context( 12 );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->seed_old_photo( $ctx, $i + 1 );
		}

		$result = $ctx['service']->purge_batch( 2 );
		self::assertSame( 2, $result->examined() );
		self::assertSame( 2, $result->purged() );
	}

	/**
	 * @param int $months Retention months.
	 * @return array{service:PhotoRetentionService,media:InMemoryMediaRepository,storage:FakePhotoStorage,events:InMemoryEventRepository}
	 */
	private function context( int $months ): array {
		$media   = new InMemoryMediaRepository();
		$storage = new FakePhotoStorage();
		$events  = new InMemoryEventRepository();
		$clock   = new class() implements \MPCF\Domain\Clock {
			public function now(): DateTimeImmutable {
				return new DateTimeImmutable( '2026-08-06 12:00:00', new DateTimeZone( 'UTC' ) );
			}
		};

		$service = new PhotoRetentionService(
			$media,
			$storage,
			$events,
			new EventDispatcher(),
			$clock,
			static fn(): int => $months
		);

		return array(
			'service' => $service,
			'media'   => $media,
			'storage' => $storage,
			'events'  => $events,
		);
	}

	/**
	 * @param array{media:InMemoryMediaRepository,storage:FakePhotoStorage} $ctx Context.
	 * @param int                                                           $seq Sequence / path token.
	 */
	private function seed_old_photo( array $ctx, int $seq = 1 ): PhotoRecord {
		$file  = "mpcf/photos/2024/01/1/a{$seq}.jpg";
		$thumb = "mpcf/photos/2024/01/1/a{$seq}-thumb.jpg";
		$ctx['storage']->put( $file, 'canonical-bytes' );
		$ctx['storage']->put( $thumb, 'thumb-bytes' );

		$id = $ctx['media']->insert(
			PhotoRecord::from_array(
				array(
					'fulfillment_id'     => 1,
					'package_id'         => 2,
					'kind'               => PhotoKind::PACKAGE,
					'file_path'          => $file,
					'thumb_path'         => $thumb,
					'mime'               => 'image/jpeg',
					'bytes'              => 100,
					'sha256'             => str_repeat( 'cd', 32 ),
					'processing_version' => 1,
					'width'              => 10,
					'height'             => 10,
					'seq'                => $seq,
					'captured_by'        => null,
					'created_at'         => new DateTimeImmutable( '2024-01-15 10:00:00', new DateTimeZone( 'UTC' ) ),
					'deleted_at'         => null,
					'purged_at'          => null,
				)
			)
		);

		$record = $ctx['media']->get( $id );
		self::assertNotNull( $record );

		return $record;
	}
}
