<?php
/**
 * Guards invariant I6: Domain, Engine and Application are WordPress-free.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WordPress-free core layers guard.
 *
 * Milestone 0 ships no class in Domain, Engine or Application yet, so the
 * real scan below passes vacuously — it starts doing real work the moment
 * Milestone 1 adds the first class to one of these namespaces. The second
 * test proves the detection logic itself works, independent of whether
 * production code currently trips it: it scans a deliberately violating
 * fixture and asserts the violation is caught.
 */
final class DomainPurityGuardTest extends TestCase {

	private const GUARDED_DIRECTORIES = array( 'Domain', 'Engine', 'Application' );

	/**
	 * WordPress/WooCommerce symbol markers that must never appear in the
	 * guarded layers. Deliberately simple substring markers rather than a
	 * full PHP parser — false positives are safe (they just mean tightening
	 * a comment), false negatives are not.
	 */
	private const FORBIDDEN_MARKERS = array(
		'WP_',
		'wp_',
		'WC_',
		'wc_',
		'\\WP_',
		'\\WC_',
		'WooCommerce',
		'get_option(',
		'add_action(',
		'add_filter(',
	);

	public function test_no_wordpress_symbol_in_domain_engine_or_application(): void {
		$violations = $this->scan( dirname( __DIR__, 2 ) . '/src' );

		self::assertSame( array(), $violations );
	}

	public function test_the_scan_itself_catches_a_deliberately_planted_violation(): void {
		$fixture_root = sys_get_temp_dir() . '/mpcf-domain-purity-fixture-' . uniqid();
		$domain_dir   = $fixture_root . '/Domain';
		mkdir( $domain_dir, 0777, true );

		file_put_contents(
			$domain_dir . '/Tainted.php',
			"<?php\nnamespace MPCF\\Domain;\nfinal class Tainted {\n\tpublic function save(): void {\n\t\tadd_action( 'init', static function () {} );\n\t}\n}\n"
		);

		$violations = $this->scan( $fixture_root );

		$this->remove_directory( $fixture_root );

		self::assertNotSame( array(), $violations, 'The scan must catch a WordPress call planted inside Domain/.' );
	}

	/**
	 * @return list<string>
	 */
	private function scan( string $src_root ): array {
		$violations = array();

		foreach ( self::GUARDED_DIRECTORIES as $directory ) {
			$path = $src_root . '/' . $directory;

			if ( ! is_dir( $path ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );

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
		}

		return $violations;
	}

	private function remove_directory( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );

		foreach ( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}

		rmdir( $path );
	}
}
