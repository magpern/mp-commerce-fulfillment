<?php
/**
 * Guards invariant I11 for the REST layer: mpcf/v1 never bypasses
 * Application services, and every registered route is capability-checked.
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
 * `src/Api/` (`mpcf/v1`) must consume the same `Application\*` services
 * `src/Admin/` does — never `$wpdb` or a concrete repository class
 * directly — and every `register_rest_route()` entry must declare its own
 * `permission_callback`, never relying on WordPress's default "logged in"
 * fallback. `$wpdb`/WooCommerce-symbol confinement for this layer is
 * already proven by `DbConfinementGuardTest`/`WooConfinementGuardTest`,
 * which scan the whole of `src/` (this directory included) — this class
 * adds the two checks those do not cover: the repository-class scan
 * (`AdminBoundaryGuardTest`'s equivalent is scoped to `src/Admin/` only)
 * and the permission-callback presence check.
 */
final class RestBoundaryGuardTest extends TestCase {

	private const API_PATH = 'src/Api';

	public function test_api_never_references_a_concrete_repository_class(): void {
		self::assertSame( array(), $this->scan_for_repository_reference( dirname( __DIR__, 2 ) . '/' . self::API_PATH ) );
	}

	public function test_every_registered_route_declares_its_own_permission_callback(): void {
		$violations = array();

		foreach ( $this->php_files( dirname( __DIR__, 2 ) . '/' . self::API_PATH ) as $file ) {
			$contents = (string) file_get_contents( $file );

			if ( ! str_contains( $contents, 'register_rest_route(' ) ) {
				continue;
			}

			$route_entries        = substr_count( $contents, "'methods'" );
			$permission_callbacks = substr_count( $contents, "'permission_callback'" );

			if ( $route_entries !== $permission_callbacks ) {
				$violations[] = "{$file} declares {$route_entries} route entr(y/ies) but only {$permission_callbacks} permission_callback(s).";
			}
		}

		self::assertSame( array(), $violations );
	}

	public function test_the_repository_scan_itself_catches_a_planted_violation(): void {
		$fixture_root = sys_get_temp_dir() . '/mpcf-rest-boundary-fixture-' . uniqid();
		mkdir( $fixture_root, 0777, true );

		file_put_contents(
			$fixture_root . '/Tainted.php',
			"<?php\nnamespace MPCF\\Api\\Rest;\nuse MPCF\\Infrastructure\\Database\\WpdbFulfillmentRepository;\nfinal class Tainted {\n\tpublic function handle( WpdbFulfillmentRepository \$repository ): void {\n\t}\n}\n"
		);

		$violations = $this->scan_for_repository_reference( $fixture_root );

		$this->remove_directory( $fixture_root );

		self::assertNotSame( array(), $violations, 'The scan must catch a concrete repository class referenced from the REST layer.' );
	}

	public function test_the_permission_callback_scan_itself_catches_a_planted_violation(): void {
		$fixture_root = sys_get_temp_dir() . '/mpcf-rest-boundary-fixture-' . uniqid();
		mkdir( $fixture_root, 0777, true );

		file_put_contents(
			$fixture_root . '/Tainted.php',
			"<?php\nnamespace MPCF\\Api\\Rest;\nfinal class Tainted {\n\tpublic function register_routes(): void {\n\t\tregister_rest_route( 'mpcf/v1', '/tainted', array(\n\t\t\tarray( 'methods' => 'GET', 'callback' => array( \$this, 'noop' ) ),\n\t\t) );\n\t}\n}\n"
		);

		$violations = array();

		foreach ( $this->php_files( $fixture_root ) as $file ) {
			$contents = (string) file_get_contents( $file );

			if ( ! str_contains( $contents, 'register_rest_route(' ) ) {
				continue;
			}

			if ( substr_count( $contents, "'methods'" ) !== substr_count( $contents, "'permission_callback'" ) ) {
				$violations[] = $file;
			}
		}

		$this->remove_directory( $fixture_root );

		self::assertNotSame( array(), $violations, 'The scan must catch a route entry with no permission_callback.' );
	}

	/**
	 * @return list<string>
	 */
	private function scan_for_repository_reference( string $root ): array {
		$violations = array();

		foreach ( $this->php_files( $root ) as $file ) {
			$contents = (string) file_get_contents( $file );

			if ( str_contains( $contents, 'Infrastructure\\Database\\Wpdb' ) || 1 === preg_match( '/\bWpdb[A-Za-z]+\b/', $contents ) ) {
				$violations[] = $file . ' violates the REST boundary.';
			}
		}

		return $violations;
	}

	/**
	 * @return list<string>
	 */
	private function php_files( string $root ): array {
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'php' === strtolower( $file->getExtension() ) ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
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
