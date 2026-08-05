<?php
/**
 * Tests for the document template registry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Documents;

use MPCF\Documents\TemplateRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests for bundled / theme / filter template resolution.
 */
final class TemplateRegistryTest extends TestCase {

	protected function tearDown(): void {
		remove_all_filters( 'mpcf_document_template' );
		parent::tearDown();
	}

	public function test_resolve_finds_the_bundled_packing_slip_template(): void {
		$path = ( new TemplateRegistry() )->resolve( 'packing_slip' );

		self::assertNotNull( $path );
		self::assertStringEndsWith( 'templates/documents/packing-slip.php', $path );
		self::assertFileExists( $path );
	}

	public function test_resolve_returns_null_for_an_unknown_doc_type(): void {
		self::assertNull( ( new TemplateRegistry() )->resolve( 'pick_list' ) );
	}

	public function test_resolve_rejects_a_path_traversal_attempt(): void {
		self::assertNull( ( new TemplateRegistry() )->resolve( '../../../etc/passwd' ) );
	}

	public function test_filter_can_supply_an_explicit_template_path(): void {
		$bundled = ( new TemplateRegistry() )->resolve( 'packing_slip' );
		self::assertNotNull( $bundled );

		$tmpdir = sys_get_temp_dir() . '/mpcf-doc-tpl-' . uniqid();
		mkdir( $tmpdir );
		$custom = $tmpdir . '/custom-slip.php';
		file_put_contents( $custom, "<?php echo 'custom';\n" );

		add_filter(
			'mpcf_document_template',
			static function () use ( $custom ) {
				return $custom;
			}
		);

		$resolved = ( new TemplateRegistry() )->resolve( 'packing_slip' );

		self::assertSame( realpath( $custom ), $resolved );

		unlink( $custom );
		rmdir( $tmpdir );
	}

	public function test_theme_override_wins_over_bundled(): void {
		$theme_docs = sys_get_temp_dir() . '/mpcf-theme-docs-' . uniqid();
		mkdir( $theme_docs, 0777, true );
		$override = $theme_docs . '/packing-slip.php';
		file_put_contents( $override, "<?php echo 'theme';\n" );

		$resolved = ( new TemplateRegistry( $theme_docs ) )->resolve( 'packing_slip' );

		self::assertSame( realpath( $override ), $resolved );

		unlink( $override );
		rmdir( $theme_docs );
	}

	public function test_invalid_filter_path_falls_back_to_bundled(): void {
		add_filter(
			'mpcf_document_template',
			static function () {
				return '/no/such/template.php';
			}
		);

		$path = ( new TemplateRegistry() )->resolve( 'packing_slip' );

		self::assertNotNull( $path );
		self::assertStringEndsWith( 'templates/documents/packing-slip.php', $path );
	}

	public function test_theme_traversal_is_rejected(): void {
		$theme_docs = sys_get_temp_dir() . '/mpcf-theme-docs-' . uniqid();
		mkdir( $theme_docs, 0777, true );
		file_put_contents( $theme_docs . '/packing-slip.php', "<?php\n" );

		// Outside the theme root — even if named like a template, validation
		// with allowed_root must reject escapes. Simulate by resolving a
		// type whose hyphenated name cannot leave the dir via our sanitize.
		self::assertNull( ( new TemplateRegistry( $theme_docs ) )->resolve( '../packing_slip' ) );

		unlink( $theme_docs . '/packing-slip.php' );
		rmdir( $theme_docs );
	}
}
