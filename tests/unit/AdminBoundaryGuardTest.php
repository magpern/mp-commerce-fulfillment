<?php
/**
 * Guards invariant I11: Admin never bypasses Application services.
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
 * Admin (`src/Admin/`) must never touch `$wpdb` or a concrete repository
 * class directly — every read/write flows through an `Application\*`
 * service, the same services a future REST layer will use (invariant I11).
 * Every mutation-capable screen must also perform its own capability check
 * before acting; the page-level `capability()` method WordPress uses to
 * gate menu visibility is not itself a substitute for that (a page can be
 * visible yet still expose more than one action, each needing its own
 * check — e.g. the Queue is visible to an operator, but only some of its
 * bulk-action targets are).
 */
final class AdminBoundaryGuardTest extends TestCase {

	private const ADMIN_PATH = 'src/Admin';

	/**
	 * Admin files that mutate state and must therefore call
	 * `current_user_can()` themselves, not only declare a page-level
	 * `capability()`.
	 *
	 * @var list<string>
	 */
	private const MUTATING_FILES = array( 'QueuePage.php', 'FulfillmentDetailPage.php' );

	public function test_admin_never_references_wpdb_directly(): void {
		self::assertSame( array(), $this->scan_for_wpdb( dirname( __DIR__, 2 ) . '/' . self::ADMIN_PATH ) );
	}

	public function test_admin_never_references_a_concrete_repository_class(): void {
		self::assertSame( array(), $this->scan_for_repository_reference( dirname( __DIR__, 2 ) . '/' . self::ADMIN_PATH ) );
	}

	public function test_every_mutating_admin_screen_performs_its_own_capability_check(): void {
		$missing = array();

		foreach ( self::MUTATING_FILES as $filename ) {
			$path = dirname( __DIR__, 2 ) . '/' . self::ADMIN_PATH . '/' . $filename;

			if ( ! str_contains( (string) file_get_contents( $path ), 'current_user_can(' ) ) {
				$missing[] = $filename;
			}
		}

		self::assertSame( array(), $missing, 'Every mutating Admin screen must call current_user_can() before acting.' );
	}

	public function test_the_wpdb_scan_itself_catches_a_planted_violation(): void {
		$fixture_root = sys_get_temp_dir() . '/mpcf-admin-boundary-fixture-' . uniqid();
		mkdir( $fixture_root, 0777, true );

		file_put_contents(
			$fixture_root . '/Tainted.php',
			"<?php\nnamespace MPCF\\Admin;\nfinal class Tainted {\n\tpublic function render(): void {\n\t\tglobal \$wpdb;\n\t\t\$wpdb->get_results( 'SELECT 1' );\n\t}\n}\n"
		);

		$violations = $this->scan_for_wpdb( $fixture_root );

		$this->remove_directory( $fixture_root );

		self::assertNotSame( array(), $violations, 'The scan must catch $wpdb referenced directly from Admin.' );
	}

	public function test_the_repository_scan_itself_catches_a_planted_violation(): void {
		$fixture_root = sys_get_temp_dir() . '/mpcf-admin-boundary-fixture-' . uniqid();
		mkdir( $fixture_root, 0777, true );

		file_put_contents(
			$fixture_root . '/Tainted.php',
			"<?php\nnamespace MPCF\\Admin;\nuse MPCF\\Infrastructure\\Database\\WpdbFulfillmentRepository;\nfinal class Tainted {\n\tpublic function render( WpdbFulfillmentRepository \$repository ): void {\n\t}\n}\n"
		);

		$violations = $this->scan_for_repository_reference( $fixture_root );

		$this->remove_directory( $fixture_root );

		self::assertNotSame( array(), $violations, 'The scan must catch a concrete repository class referenced directly from Admin.' );
	}

	/**
	 * @return list<string>
	 */
	private function scan_for_wpdb( string $root ): array {
		return $this->scan(
			$root,
			static fn( string $contents ): bool => str_contains( $contents, '$wpdb' )
		);
	}

	/**
	 * @return list<string>
	 */
	private function scan_for_repository_reference( string $root ): array {
		return $this->scan(
			$root,
			static fn( string $contents ): bool => str_contains( $contents, 'Infrastructure\\Database\\Wpdb' ) || 1 === preg_match( '/\bWpdb[A-Za-z]+\b/', $contents )
		);
	}

	/**
	 * @param string                $root         Directory to scan.
	 * @param callable(string):bool $is_violation Predicate run against each file's contents.
	 * @return list<string>
	 */
	private function scan( string $root, callable $is_violation ): array {
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$violations = array();
		$iterator   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );

			if ( $is_violation( $contents ) ) {
				$violations[] = $file->getPathname() . ' violates the Admin boundary.';
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
