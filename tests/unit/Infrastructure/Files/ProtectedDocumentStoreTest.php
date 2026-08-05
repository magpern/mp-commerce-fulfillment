<?php
/**
 * Protected document store tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Infrastructure\Files;

use DateTimeImmutable;
use MPCF\Infrastructure\Files\ProtectedDocumentStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Path safety, atomic write, integrity, and cleanup.
 */
final class ProtectedDocumentStoreTest extends TestCase {

	/**
	 * @var string
	 */
	private string $root;

	/**
	 * @var ProtectedDocumentStore
	 */
	private ProtectedDocumentStore $store;

	protected function setUp(): void {
		$this->root  = sys_get_temp_dir() . '/mpcf-pds-' . uniqid( '', true );
		$this->store = new ProtectedDocumentStore( $this->root );
	}

	protected function tearDown(): void {
		$this->rm_tree( $this->root );
		parent::tearDown();
	}

	public function test_write_creates_protected_relative_path_with_integrity(): void {
		$html   = '<html><body>slip</body></html>';
		$result = $this->store->write( 42, 'packing_slip', $html, new DateTimeImmutable( '2026-08-04 12:00:00' ) );

		self::assertStringStartsWith( 'mpcf/documents/2026/08/42/packing_slip-', $result->relative_path() );
		self::assertStringEndsWith( '.html', $result->relative_path() );
		self::assertSame( strlen( $html ), $result->byte_size() );
		self::assertSame( hash( 'sha256', $html ), $result->sha256() );
		self::assertSame( ProtectedDocumentStore::MIME_HTML, $result->mime_type() );
		self::assertFileExists( $result->absolute_path() );
		self::assertFileExists( $this->root . '/mpcf/.htaccess' );
		self::assertStringContainsString( 'Deny from all', (string) file_get_contents( $this->root . '/mpcf/.htaccess' ) );
	}

	public function test_rejects_traversal_in_relative_resolution(): void {
		self::assertNull( $this->store->absolute_path( 'mpcf/documents/../secrets.txt' ) );
		self::assertNull( $this->store->absolute_path( 'other/documents/1/a.html' ) );
	}

	public function test_rejects_invalid_doc_type(): void {
		$this->expectException( RuntimeException::class );
		$this->store->write( 1, '../evil', '<html></html>', new DateTimeImmutable() );
	}

	public function test_delete_relative_removes_orphan(): void {
		$result = $this->store->write( 7, 'picking_list', '<html>x</html>', new DateTimeImmutable( '2026-01-01' ) );
		self::assertTrue( $this->store->delete_relative( $result->relative_path() ) );
		self::assertFileDoesNotExist( $result->absolute_path() );
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
