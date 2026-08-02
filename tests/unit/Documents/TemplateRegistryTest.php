<?php
/**
 * Tests for the bundled document template registry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Documents;

use MPCF\Documents\TemplateRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the bundled document template registry.
 */
final class TemplateRegistryTest extends TestCase {

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
}
