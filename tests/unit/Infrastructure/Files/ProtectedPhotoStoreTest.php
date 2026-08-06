<?php
/**
 * Protected photo store tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Infrastructure\Files;

use DateTimeImmutable;
use MPCF\Infrastructure\Files\ProtectedPhotoStore;
use PHPUnit\Framework\TestCase;

/**
 * Path safety, atomic pair write, integrity, and cleanup.
 */
final class ProtectedPhotoStoreTest extends TestCase {

	/**
	 * @var string
	 */
	private string $root;

	/**
	 * @var ProtectedPhotoStore
	 */
	private ProtectedPhotoStore $store;

	protected function setUp(): void {
		$this->root  = sys_get_temp_dir() . '/mpcf-pps-' . uniqid( '', true );
		$this->store = new ProtectedPhotoStore( $this->root );
	}

	protected function tearDown(): void {
		$this->rm_tree( $this->root );
		parent::tearDown();
	}

	public function test_write_pair_creates_protected_paths_with_integrity(): void {
		$canonical = 'CANONICAL-JPEG';
		$thumb     = 'THUMB-JPEG';
		$result    = $this->store->write_pair( 42, $canonical, $thumb, 'jpg', new DateTimeImmutable( '2026-08-06 12:00:00' ) );

		self::assertStringStartsWith( 'mpcf/photos/2026/08/42/', $result->relative_path() );
		self::assertStringEndsWith( '.jpg', $result->relative_path() );
		self::assertStringEndsWith( '-thumb.jpg', $result->thumb_relative_path() );
		self::assertSame( strlen( $canonical ), $result->byte_size() );
		self::assertSame( hash( 'sha256', $canonical ), $result->sha256() );
		self::assertFileExists( $result->absolute_path() );
		self::assertFileExists( $result->thumb_absolute_path() );
		self::assertFileExists( $this->root . '/mpcf/.htaccess' );
		self::assertFileExists( $this->root . '/mpcf/photos/.htaccess' );
		self::assertStringContainsString( 'Deny from all', (string) file_get_contents( $this->root . '/mpcf/.htaccess' ) );
		self::assertTrue( $this->store->belongs_to_photo_root( $result->relative_path() ) );
	}

	public function test_rejects_traversal_and_non_photo_prefix(): void {
		self::assertNull( $this->store->absolute_path( 'mpcf/photos/../secrets.jpg' ) );
		self::assertNull( $this->store->absolute_path( 'mpcf/documents/1/a.html' ) );
		self::assertFalse( $this->store->belongs_to_photo_root( 'mpcf/documents/1/a.html' ) );
	}

	public function test_delete_relative_removes_orphan(): void {
		$result = $this->store->write_pair( 7, 'A', 'B', 'jpg', new DateTimeImmutable( '2026-01-01' ) );
		self::assertTrue( $this->store->delete_relative( $result->relative_path() ) );
		self::assertTrue( $this->store->delete_relative( $result->thumb_relative_path() ) );
		self::assertFileDoesNotExist( $result->absolute_path() );
		self::assertFileDoesNotExist( $result->thumb_absolute_path() );
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
