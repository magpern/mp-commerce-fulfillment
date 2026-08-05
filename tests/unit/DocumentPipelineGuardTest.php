<?php
/**
 * Guards §10's "assembly != rendering, and one orchestrator" rule:
 * Application\DocumentService is the only class that calls a renderer.
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
 * "Documents printed" must be a reliable audit fact (§10) — that only
 * holds if nothing outside `DocumentService` can render a document
 * without going through its assemble → render → record → audit sequence.
 *
 * M4-A: scan for `HtmlRenderer` and `DocumentRendererInterface` usage.
 * Controllers/admin must not reference either; only DocumentService
 * orchestrates rendering. Plugin may construct HtmlRenderer for injection.
 */
final class DocumentPipelineGuardTest extends TestCase {

	/**
	 * Files allowed to reference HtmlRenderer / DocumentRendererInterface.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_HTML_RENDERER = array(
		'Application/DocumentService.php',
		'Documents/HtmlRenderer.php',
		'Documents/DocumentRendererInterface.php',
	);

	/**
	 * @var list<string>
	 */
	private const ALLOWED_RENDERER_INTERFACE = array(
		'Application/DocumentService.php',
		'Documents/HtmlRenderer.php',
		'Documents/DocumentRendererInterface.php',
	);

	public function test_only_documentservice_references_htmlrenderer_within_src(): void {
		self::assertSame( array(), $this->scan( dirname( __DIR__, 2 ) . '/src', 'HtmlRenderer', self::ALLOWED_HTML_RENDERER ) );
	}

	public function test_only_documentservice_and_implementations_reference_renderer_interface(): void {
		self::assertSame(
			array(),
			$this->scan( dirname( __DIR__, 2 ) . '/src', 'DocumentRendererInterface', self::ALLOWED_RENDERER_INTERFACE )
		);
	}

	public function test_htmlrenderer_implements_document_renderer_interface(): void {
		self::assertTrue(
			is_a( \MPCF\Documents\HtmlRenderer::class, \MPCF\Documents\DocumentRendererInterface::class, true )
		);
	}

	public function test_the_scan_itself_catches_a_second_caller(): void {
		$fixture_root = sys_get_temp_dir() . '/mpcf-document-pipeline-fixture-' . uniqid();
		$admin_dir    = $fixture_root . '/Admin';
		mkdir( $admin_dir, 0777, true );

		file_put_contents(
			$admin_dir . '/Tainted.php',
			"<?php\nnamespace MPCF\\Admin;\nuse MPCF\\Documents\\HtmlRenderer;\nfinal class Tainted {\n\tpublic function render( HtmlRenderer \$renderer ): void {\n\t}\n}\n"
		);

		$violations = $this->scan( $fixture_root, 'HtmlRenderer', self::ALLOWED_HTML_RENDERER );

		$this->remove_directory( $fixture_root );

		self::assertNotSame( array(), $violations, 'The scan must catch a second class referencing HtmlRenderer.' );
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

			$relative = ltrim( str_replace( $src_root, '', $file->getPathname() ), '/' );

			if ( 'Plugin.php' === $relative || in_array( $relative, $allowed, true ) ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );

			if ( str_contains( $contents, $marker ) ) {
				$violations[] = $file->getPathname() . " references {$marker} outside DocumentService.";
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
