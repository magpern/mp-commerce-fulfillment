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
 * The scan looks for the class name `HtmlRenderer` itself, not a call
 * token like `->render(` — that generic a token already collides with an
 * unrelated `render()` method elsewhere in `src/` (the MPDS page shell's
 * navigation renderer), so the class name is the only unambiguous marker.
 */
final class DocumentPipelineGuardTest extends TestCase {

	/**
	 * Files allowed to reference `HtmlRenderer`: the orchestrator that
	 * calls it, the composition root that constructs it to inject, and
	 * the class's own definition file (which necessarily names itself).
	 *
	 * @var list<string>
	 */
	private const ALLOWED_FILES = array(
		'Application/DocumentService.php',
		'Documents/HtmlRenderer.php',
	);

	private const MARKER = 'HtmlRenderer';

	public function test_only_documentservice_references_htmlrenderer_within_src(): void {
		self::assertSame( array(), $this->scan( dirname( __DIR__, 2 ) . '/src' ) );
	}

	public function test_the_scan_itself_catches_a_second_caller(): void {
		$fixture_root = sys_get_temp_dir() . '/mpcf-document-pipeline-fixture-' . uniqid();
		$admin_dir    = $fixture_root . '/Admin';
		mkdir( $admin_dir, 0777, true );

		file_put_contents(
			$admin_dir . '/Tainted.php',
			"<?php\nnamespace MPCF\\Admin;\nuse MPCF\\Documents\\HtmlRenderer;\nfinal class Tainted {\n\tpublic function render( HtmlRenderer \$renderer ): void {\n\t}\n}\n"
		);

		$violations = $this->scan( $fixture_root );

		$this->remove_directory( $fixture_root );

		self::assertNotSame( array(), $violations, 'The scan must catch a second class referencing HtmlRenderer.' );
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

			if ( 'Plugin.php' === $relative || in_array( $relative, self::ALLOWED_FILES, true ) ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );

			if ( str_contains( $contents, self::MARKER ) ) {
				$violations[] = $file->getPathname() . ' references HtmlRenderer outside DocumentService.';
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
