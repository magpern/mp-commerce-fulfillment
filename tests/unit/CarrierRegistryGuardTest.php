<?php
/**
 * Guards M5-A carrier architecture: immutable definitions, single filter
 * host, single template expander.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use FilesystemIterator;
use MPCF\Domain\Shipping\Carrier;
use MPCF\Domain\TrackingUrlResolver;
use MPCF\Infrastructure\Carriers\TemplateTrackingUrlResolver;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;

/**
 * Keeps the carrier registry on the DocumentTypeRegistry resilience path
 * and ensures TrackingUrlResolver owns template expansion.
 */
final class CarrierRegistryGuardTest extends TestCase {

	/**
	 * Files allowed to apply the mpcf_carriers filter.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_MPCF_CARRIERS_FILTER = array(
		'Infrastructure/Carriers/BundledCarrierRegistry.php',
	);

	public function test_carrier_value_object_is_immutable(): void {
		$reflection = new ReflectionClass( Carrier::class );

		self::assertTrue( $reflection->isFinal() );
		self::assertTrue( $reflection->getConstructor()->isPrivate() );

		foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			self::assertDoesNotMatchRegularExpression( '/^set/', $method->getName() );
		}
	}

	public function test_template_resolver_implements_tracking_url_resolver(): void {
		self::assertTrue(
			is_a( TemplateTrackingUrlResolver::class, TrackingUrlResolver::class, true )
		);
	}

	public function test_only_bundled_registry_applies_mpcf_carriers_filter(): void {
		self::assertSame(
			array(),
			$this->scan( dirname( __DIR__, 2 ) . '/src', "apply_filters( 'mpcf_carriers'", self::ALLOWED_MPCF_CARRIERS_FILTER )
		);
	}

	public function test_tracking_placeholder_expansion_stays_in_resolver(): void {
		$violations = $this->scan(
			dirname( __DIR__, 2 ) . '/src',
			"str_replace( '{tracking}'",
			array( 'Infrastructure/Carriers/TemplateTrackingUrlResolver.php' )
		);

		self::assertSame( array(), $violations );
	}

	public function test_the_scan_itself_catches_a_second_filter_host(): void {
		$fixture_root = sys_get_temp_dir() . '/mpcf-carrier-registry-fixture-' . uniqid();
		$admin_dir    = $fixture_root . '/Admin';
		mkdir( $admin_dir, 0777, true );

		file_put_contents(
			$admin_dir . '/Tainted.php',
			"<?php\nnamespace MPCF\\Admin;\nfinal class Tainted {\n\tpublic function boot(): void {\n\t\tapply_filters( 'mpcf_carriers', array() );\n\t}\n}\n"
		);

		$violations = $this->scan( $fixture_root, "apply_filters( 'mpcf_carriers'", self::ALLOWED_MPCF_CARRIERS_FILTER );

		$this->remove_directory( $fixture_root );

		self::assertNotSame( array(), $violations );
	}

	/**
	 * Scans PHP files under a root for a forbidden marker.
	 *
	 * @param string   $src_root Source root to scan.
	 * @param string   $marker   Forbidden substring.
	 * @param string[] $allowed  Relative paths under src/ that may contain the marker.
	 * @return string[]
	 */
	private function scan( string $src_root, string $marker, array $allowed ): array {
		if ( ! is_dir( $src_root ) ) {
			return array();
		}

		$violations = array();
		$iterator   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src_root, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$relative = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $src_root ) ) ), '/' );

			if ( in_array( $relative, $allowed, true ) ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );

			if ( str_contains( $contents, $marker ) ) {
				$violations[] = $relative;
			}
		}

		return $violations;
	}

	/**
	 * Recursively removes a directory tree.
	 *
	 * @param string $directory Directory to remove.
	 */
	private function remove_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() );
			}
		}

		rmdir( $directory );
	}
}
