<?php
/**
 * Guards invariant I7: $wpdb is confined to src/Infrastructure/Database/.
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
 * $wpdb confinement guard.
 */
final class DbConfinementGuardTest extends TestCase {

	private const ALLOWED_PATH = 'Infrastructure/Database';

	public function test_wpdb_appears_only_in_infrastructure_database(): void {
		$violations = $this->scan( dirname( __DIR__, 2 ) . '/src' );

		self::assertSame( array(), $violations );
	}

	public function test_the_scan_itself_catches_wpdb_used_outside_the_allowed_path(): void {
		$fixture_root = sys_get_temp_dir() . '/mpcf-db-confinement-fixture-' . uniqid();
		$woo_dir      = $fixture_root . '/Woo';
		mkdir( $woo_dir, 0777, true );

		file_put_contents(
			$woo_dir . '/Tainted.php',
			"<?php\nnamespace MPCF\\Woo;\nfinal class Tainted {\n\tpublic function query(): void {\n\t\tglobal \$wpdb;\n\t\t\$wpdb->get_results( 'SELECT 1' );\n\t}\n}\n"
		);

		$violations = $this->scan( $fixture_root );

		$this->remove_directory( $fixture_root );

		self::assertNotSame( array(), $violations, 'The scan must catch $wpdb used outside Infrastructure/Database.' );
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

			$relative = ltrim( str_replace( $src_root, '', $file->getPathname() ), '/' );

			if ( str_starts_with( $relative, self::ALLOWED_PATH ) ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );

			if ( str_contains( $contents, '$wpdb' ) ) {
				$violations[] = $file->getPathname() . ' references $wpdb outside ' . self::ALLOWED_PATH;
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
