<?php
/**
 * Guards invariant I8: only src/Woo/ may name a WooCommerce class or hook.
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
 * WooCommerce confinement guard.
 */
final class WooConfinementGuardTest extends TestCase {

	private const ALLOWED_PATH = 'Woo';

	/**
	 * The Vendor/ subdirectory is pinned third-party content (MP Admin
	 * Design System), governed by its own repo's invariants, not this
	 * plugin's I8 — its prose is free to mention WooCommerce/WC_ in a
	 * historical comment (as it does, citing UMC's WC_Settings_Page as
	 * prior art) without meaning this plugin has coupled itself to
	 * WooCommerce outside src/Woo/.
	 */
	private const EXCLUDED_PATH = 'Vendor';

	/**
	 * @var list<string>
	 */
	private const FORBIDDEN_MARKERS = array( 'WooCommerce', 'WC_', '\\WC_', "'woocommerce_", '"woocommerce_' );

	public function test_no_woocommerce_symbol_outside_src_woo(): void {
		$violations = $this->scan( dirname( __DIR__, 2 ) . '/src' );

		self::assertSame( array(), $violations );
	}

	public function test_the_scan_itself_catches_a_woocommerce_hook_outside_woo(): void {
		$fixture_root = sys_get_temp_dir() . '/mpcf-woo-confinement-fixture-' . uniqid();
		$admin_dir    = $fixture_root . '/Admin';
		mkdir( $admin_dir, 0777, true );

		file_put_contents(
			$admin_dir . '/Tainted.php',
			"<?php\nnamespace MPCF\\Admin;\nfinal class Tainted {\n\tpublic function register(): void {\n\t\tadd_action( 'woocommerce_order_status_changed', array( \$this, 'noop' ) );\n\t}\n}\n"
		);

		$violations = $this->scan( $fixture_root );

		$this->remove_directory( $fixture_root );

		self::assertNotSame( array(), $violations, 'The scan must catch a woocommerce_* hook registered outside src/Woo/.' );
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

			if ( str_starts_with( $relative, self::ALLOWED_PATH . '/' ) || str_starts_with( $relative, self::EXCLUDED_PATH . '/' ) ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );

			foreach ( self::FORBIDDEN_MARKERS as $marker ) {
				if ( str_contains( $contents, $marker ) ) {
					$violations[] = $file->getPathname() . ' references "' . $marker . '" outside src/' . self::ALLOWED_PATH . '/';
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
