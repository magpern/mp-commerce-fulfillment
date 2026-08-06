<?php
/**
 * PhotoService unit tests (no GD required).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Photos;

use DateTimeImmutable;
use InvalidArgumentException;
use MPCF\Application\EventDispatcher;
use MPCF\Application\Photos\PhotoConfig;
use MPCF\Application\Photos\PhotoService;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\Media\PhotoKind;
use MPCF\Domain\Media\PhotoRecord;
use MPCF\Domain\Repository\MediaRepository;
use MPCF\Domain\Shipping\Package;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Infrastructure\Files\ProtectedPhotoStore;
use MPCF\Tests\Unit\Application\Doubles\FixedClock;
use MPCF\Tests\Unit\Application\Doubles\InMemoryEventRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryMediaRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryPackageRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryShipmentRepository;
use MPCF\Tests\Unit\Support\FakeImageProcessor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Capture ownership, audit, soft-delete idempotency, and storage compensation.
 */
final class PhotoServiceTest extends TestCase {

	/**
	 * @var string
	 */
	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/mpcf-photo-svc-' . uniqid( '', true );
	}

	protected function tearDown(): void {
		$this->rm_tree( $this->root );
		parent::tearDown();
	}

	public function test_capture_writes_audit_and_satisfies_package_requirement(): void {
		$ctx   = $this->seed_context();
		$photo = $ctx['service']->capture(
			$ctx['fulfillment_id'],
			$ctx['package_id'],
			PhotoKind::PACKAGE,
			'raw-bytes',
			'image/jpeg',
			Actor::user( 9, 'Op' )
		);

		self::assertNotNull( $photo->id() );
		self::assertSame( PhotoKind::PACKAGE, $photo->kind() );
		self::assertTrue( $ctx['service']->requirement_satisfied( $ctx['fulfillment_id'] ) );

		$timeline = $ctx['events']->timeline_for_fulfillment( $ctx['fulfillment_id'] );
		self::assertSame( 'photo.captured', $timeline[0]['event_type'] );
		self::assertSame( $photo->sha256(), $timeline[0]['payload']['sha256'] );
		self::assertSame( $photo->id(), $timeline[0]['payload']['photo_id'] );
	}

	public function test_contents_photo_does_not_satisfy_requirement(): void {
		$ctx = $this->seed_context();

		$ctx['service']->capture(
			$ctx['fulfillment_id'],
			$ctx['package_id'],
			PhotoKind::CONTENTS,
			'raw',
			'image/jpeg',
			Actor::user( 1, 'Op' )
		);

		self::assertFalse( $ctx['service']->requirement_satisfied( $ctx['fulfillment_id'] ) );
	}

	public function test_rejects_package_from_another_fulfillment(): void {
		$fulfillments = new InMemoryFulfillmentRepository();
		$packages     = new InMemoryPackageRepository();
		$shipments    = new InMemoryShipmentRepository();
		$events       = new InMemoryEventRepository();
		$now          = new DateTimeImmutable( '2026-08-06 12:00:00' );

		$fid_a = $fulfillments->insert( Fulfillment::intake( 1, 'woocommerce', 1, 'standard', 'packing', '#1', 'A', 1, $now ) );
		$fid_b = $fulfillments->insert( Fulfillment::intake( 2, 'woocommerce', 1, 'standard', 'packing', '#2', 'B', 1, $now ) );
		$sid   = $shipments->insert( Shipment::create( $fid_b, $now ) );
		$pid   = $packages->insert( Package::create( $sid, 1, $now ) );

		$service = new PhotoService(
			new InMemoryMediaRepository(),
			new ProtectedPhotoStore( $this->root ),
			new FakeImageProcessor(),
			$fulfillments,
			$packages,
			$shipments,
			$events,
			new EventDispatcher(),
			new FixedClock( $now )
		);

		$this->expectException( InvalidArgumentException::class );
		$service->capture( $fid_a, $pid, PhotoKind::PACKAGE, 'x', 'image/jpeg', Actor::system() );
	}

	public function test_soft_delete_is_idempotent_and_audits_once(): void {
		$ctx   = $this->seed_context();
		$photo = $ctx['service']->capture(
			$ctx['fulfillment_id'],
			$ctx['package_id'],
			PhotoKind::PACKAGE,
			'raw',
			'image/jpeg',
			Actor::user( 2, 'Lead' )
		);

		$canonical = $this->root . '/' . $photo->file_path();
		$thumb     = $this->root . '/' . $photo->thumb_path();
		self::assertFileExists( $canonical );
		self::assertFileExists( $thumb );

		$deleted = $ctx['service']->soft_delete( (int) $photo->id(), Actor::user( 2, 'Lead' ) );
		self::assertTrue( $deleted->is_deleted() );
		self::assertSame( $photo->sha256(), $deleted->sha256() );
		self::assertFileExists( $canonical, 'Soft-delete must preserve canonical bytes.' );
		self::assertFileExists( $thumb, 'Soft-delete must preserve thumbnail bytes.' );

		$again = $ctx['service']->soft_delete( (int) $photo->id(), Actor::user( 2, 'Lead' ) );
		self::assertTrue( $again->is_deleted() );

		$timeline = $ctx['events']->timeline_for_fulfillment( $ctx['fulfillment_id'] );
		$types    = array_column( $timeline, 'event_type' );
		self::assertSame( array( 'photo.captured', 'photo.deleted' ), $types );
		self::assertSame( 1, $deleted->processing_version() );
		self::assertSame( $photo->sha256(), $timeline[1]['payload']['sha256'] );
		self::assertSame( 1, $timeline[1]['payload']['processing_version'] );
		self::assertFalse( $ctx['service']->requirement_satisfied( $ctx['fulfillment_id'] ) );
		self::assertCount( 0, $ctx['service']->list_for_fulfillment( $ctx['fulfillment_id'] ) );
		self::assertCount( 1, $ctx['service']->list_for_fulfillment( $ctx['fulfillment_id'], true ) );
	}

	public function test_db_insert_failure_deletes_orphan_files(): void {
		$fulfillments = new InMemoryFulfillmentRepository();
		$packages     = new InMemoryPackageRepository();
		$shipments    = new InMemoryShipmentRepository();
		$events       = new InMemoryEventRepository();
		$now          = new DateTimeImmutable( '2026-08-06 12:00:00' );
		$store        = new ProtectedPhotoStore( $this->root );

		$fid = $fulfillments->insert( Fulfillment::intake( 3, 'woocommerce', 1, 'standard', 'packing', '#3', 'C', 1, $now ) );
		$sid = $shipments->insert( Shipment::create( $fid, $now ) );
		$pid = $packages->insert( Package::create( $sid, 1, $now ) );

		$failing = new class() implements MediaRepository {
			public function insert( PhotoRecord $record ): int {
				throw new RuntimeException( 'db down' );
			}
			public function get( int $photo_id ): ?PhotoRecord {
				return null;
			}
			public function list_for_fulfillment( int $fulfillment_id, bool $include_deleted = false ): array {
				return array();
			}
			public function list_for_package( int $package_id, bool $include_deleted = false ): array {
				return array();
			}
			public function count_active_for_fulfillment( int $fulfillment_id ): int {
				return 0;
			}
			public function count_active_package_photos( int $fulfillment_id ): int {
				return 0;
			}
			public function next_sequence( int $fulfillment_id ): int {
				return 1;
			}
			public function soft_delete( int $photo_id, DateTimeImmutable $now ): bool {
				return false;
			}
			public function list_purge_candidates( DateTimeImmutable $cutoff, int $limit ): array {
				return array();
			}
			public function mark_purged( int $photo_id, DateTimeImmutable $now ): bool {
				return false;
			}
		};

		$service = new PhotoService(
			$failing,
			$store,
			new FakeImageProcessor( 'CANON', 'THUMB' ),
			$fulfillments,
			$packages,
			$shipments,
			$events,
			new EventDispatcher(),
			new FixedClock( $now ),
			PhotoConfig::defaults()
		);

		try {
			$service->capture( $fid, $pid, PhotoKind::PACKAGE, 'x', 'image/jpeg', Actor::system() );
			self::fail( 'Expected RuntimeException' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'Unable to record the photo', $e->getMessage() );
		}

		$photos_dir = $this->root . '/mpcf/photos';
		$jpgs       = array();
		if ( is_dir( $photos_dir ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $photos_dir, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && str_ends_with( $file->getFilename(), '.jpg' ) ) {
					$jpgs[] = $file->getPathname();
				}
			}
		}

		self::assertSame( array(), $jpgs, 'Orphan JPEG pair must be removed after DB failure.' );
		self::assertCount( 0, $events->timeline_for_fulfillment( $fid ) );
	}

	public function test_enforces_max_photos_per_fulfillment(): void {
		$ctx = $this->seed_context( PhotoConfig::create( 1024, 2000, 1, 1 ) );

		$ctx['service']->capture( $ctx['fulfillment_id'], $ctx['package_id'], PhotoKind::PACKAGE, 'a', 'image/jpeg', Actor::system() );

		$this->expectException( InvalidArgumentException::class );
		$ctx['service']->capture( $ctx['fulfillment_id'], $ctx['package_id'], PhotoKind::CONTENTS, 'b', 'image/jpeg', Actor::system() );
	}

	public function test_capture_with_version_bumps_fulfillment_version(): void {
		$ctx          = $this->seed_context();
		$fulfillments = $ctx['fulfillments'];
		$before       = $fulfillments->find( $ctx['fulfillment_id'] )->version();

		$result = $ctx['service']->capture_with_version(
			$ctx['fulfillment_id'],
			$ctx['package_id'],
			PhotoKind::PACKAGE,
			'raw',
			'image/jpeg',
			Actor::user( 1, 'Op' ),
			$before
		);

		self::assertSame( $before + 1, $result->version() );
		self::assertTrue( $result->requirement_satisfied() );
		self::assertSame( $before + 1, $fulfillments->find( $ctx['fulfillment_id'] )->version() );
	}

	public function test_capture_with_version_rejects_stale_version_before_write(): void {
		$ctx = $this->seed_context();

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'version_conflict' );
		$ctx['service']->capture_with_version(
			$ctx['fulfillment_id'],
			$ctx['package_id'],
			PhotoKind::PACKAGE,
			'raw',
			'image/jpeg',
			Actor::system(),
			999
		);
	}

	public function test_soft_delete_with_version_is_idempotent_without_touch(): void {
		$ctx          = $this->seed_context();
		$fulfillments = $ctx['fulfillments'];
		$version      = $fulfillments->find( $ctx['fulfillment_id'] )->version();

		$captured = $ctx['service']->capture_with_version(
			$ctx['fulfillment_id'],
			$ctx['package_id'],
			PhotoKind::PACKAGE,
			'raw',
			'image/jpeg',
			Actor::user( 2, 'Lead' ),
			$version
		);

		$deleted = $ctx['service']->soft_delete_with_version(
			(int) $captured->photo()->id(),
			Actor::user( 2, 'Lead' ),
			$captured->version()
		);
		self::assertTrue( $deleted->photo()->is_deleted() );
		self::assertSame( $captured->version() + 1, $deleted->version() );

		$again = $ctx['service']->soft_delete_with_version(
			(int) $captured->photo()->id(),
			Actor::user( 2, 'Lead' ),
			$deleted->version()
		);
		self::assertSame( $deleted->version(), $again->version(), 'Idempotent delete must not bump version.' );
		self::assertCount( 2, array_column( $ctx['events']->timeline_for_fulfillment( $ctx['fulfillment_id'] ), 'event_type' ) );
	}

	public function test_list_active_filters_and_sorts_deterministically(): void {
		$ctx = $this->seed_context();
		$ctx['service']->capture( $ctx['fulfillment_id'], $ctx['package_id'], PhotoKind::CONTENTS, 'a', 'image/jpeg', Actor::system() );
		$ctx['service']->capture( $ctx['fulfillment_id'], $ctx['package_id'], PhotoKind::PACKAGE, 'b', 'image/jpeg', Actor::system() );

		$packages = $ctx['service']->list_active( $ctx['fulfillment_id'], null, PhotoKind::PACKAGE );
		self::assertCount( 1, $packages );
		self::assertSame( PhotoKind::PACKAGE, $packages[0]->kind() );

		$by_package = $ctx['service']->list_active( $ctx['fulfillment_id'], $ctx['package_id'] );
		self::assertCount( 2, $by_package );
		self::assertTrue( $by_package[0]->seq() <= $by_package[1]->seq() );
	}

	public function test_get_active_and_read_bytes_respect_deleted_policy(): void {
		$ctx   = $this->seed_context();
		$photo = $ctx['service']->capture(
			$ctx['fulfillment_id'],
			$ctx['package_id'],
			PhotoKind::PACKAGE,
			'raw',
			'image/jpeg',
			Actor::system()
		);
		$id    = (int) $photo->id();

		$content = $ctx['service']->read_bytes( $id, 'content' );
		self::assertTrue( $content['ok'] );
		self::assertArrayNotHasKey( 'path', $content );
		self::assertSame( 'image/jpeg', $content['mime'] );

		$ctx['service']->soft_delete( $id, Actor::system() );
		self::assertNull( $ctx['service']->get_active( $id ) );

		$deleted = $ctx['service']->read_bytes( $id, 'content' );
		self::assertFalse( $deleted['ok'] );
		self::assertSame( 'photo_deleted', $deleted['code'] );
	}

	/**
	 * Seeds fulfillment → shipment → package with a PhotoService.
	 *
	 * @param PhotoConfig|null $config Optional config override.
	 * @return array{fulfillment_id:int,package_id:int,events:InMemoryEventRepository,service:PhotoService,fulfillments:InMemoryFulfillmentRepository}
	 */
	private function seed_context( ?PhotoConfig $config = null ): array {
		$fulfillments = new InMemoryFulfillmentRepository();
		$packages     = new InMemoryPackageRepository();
		$shipments    = new InMemoryShipmentRepository();
		$events       = new InMemoryEventRepository();
		$now          = new DateTimeImmutable( '2026-08-06 12:00:00' );

		$fid = $fulfillments->insert( Fulfillment::intake( 100, 'woocommerce', 1, 'standard', 'packing', '#100', 'Cust', 1, $now ) );
		$sid = $shipments->insert( Shipment::create( $fid, $now ) );
		$pid = $packages->insert( Package::create( $sid, 1, $now ) );

		$service = new PhotoService(
			new InMemoryMediaRepository(),
			new ProtectedPhotoStore( $this->root ),
			new FakeImageProcessor(),
			$fulfillments,
			$packages,
			$shipments,
			$events,
			new EventDispatcher(),
			new FixedClock( $now ),
			$config
		);

		return array(
			'fulfillment_id' => $fid,
			'package_id'     => $pid,
			'events'         => $events,
			'service'        => $service,
			'fulfillments'   => $fulfillments,
		);
	}

	/**
	 * @param string $path Directory to remove.
	 */
	private function rm_tree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}

		rmdir( $path );
	}
}
