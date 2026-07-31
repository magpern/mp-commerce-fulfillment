<?php
/**
 * Guards invariant I2: WooCommerce CRUD-only order access. No direct
 * `wp_posts`/`wp_postmeta` access or `get_post()`/`get_post_meta()` call
 * anywhere in src/ — order data must go through `wc_get_order()` and
 * `WC_Order` getters/setters exclusively.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Legacy order-storage confinement guard.
 */
final class LegacyOrderStorageGuardTest extends TestCase {

	/**
	 * @var list<string>
	 */
	private const FORBIDDEN_MARKERS = array( 'get_post(', 'get_post_meta(', 'wp_posts', 'wp_postmeta', 'get_post_meta_by_id' );

	public function test_no_legacy_post_storage_access_anywhere_in_src(): void {
		$violations = $this->scan( dirname( __DIR__, 2 ) . '/src' );

		self::assertSame( array(), $violations );
	}

	public function test_the_scan_itself_catches_get_post_meta_used_on_an_order(): void {
		$fixture_root = sys_get_temp_dir() . '/mpcf-legacy-order-fixture-' . uniqid();
		$woo_dir      = $fixture_root . '/Woo';
		mkdir( $woo_dir, 0777, true );

		file_put_contents(
			$woo_dir . '/Tainted.php',
			"<?php\nnamespace MPCF\\Woo;\nfinal class Tainted {\n\tpublic function read( int \$order_id ) {\n\t\treturn get_post_meta( \$order_id, '_mpcf_flag', true );\n\t}\n}\n"
		);

		$violations = $this->scan( $fixture_root );

		$this->remove_directory( $fixture_root );

		self::assertNotSame( array(), $violations, 'The scan must catch get_post_meta() used anywhere in src/, including src/Woo/.' );
	}

	/**
	 * @return list<string>
	 */
	private function scan( string $src_root ): array {
		if ( ! is_dir( $src_root ) ) {
			return array();
		}

		$violations = array();
		$iterator   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src_root, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );

			foreach ( self::FORBIDDEN_MARKERS as $marker ) {
				if ( str_contains( $contents, $marker ) ) {
					$violations[] = $file->getPathname() . ' contains forbidden marker "' . $marker . '"';
				}
			}
		}

		return $violations;
	}

	private function remove_directory( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );

		foreach ( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}

		rmdir( $path );
	}
}
