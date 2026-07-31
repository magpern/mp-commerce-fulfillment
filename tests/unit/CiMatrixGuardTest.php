<?php
/**
 * Binds the CI workflow's integration-leg coordinates to
 * docs/COMPATIBILITY.md so the matrix cannot drift without a documented,
 * conscious edit to both files together.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CI matrix documentation-sync test.
 */
final class CiMatrixGuardTest extends TestCase {

	private const ROOT = __DIR__ . '/../..';

	public function test_floor_leg_matches_the_documented_floor(): void {
		$ci  = (string) file_get_contents( self::ROOT . '/.github/workflows/ci.yml' );
		$doc = (string) file_get_contents( self::ROOT . '/docs/COMPATIBILITY.md' );

		preg_match( "/- leg: floor\\n\\s*php: '([^']+)'\\n\\s*wp_phpunit: '([^']+)'\\n\\s*wc: '([^']+)'/", $ci, $floor );

		self::assertNotEmpty( $floor, 'ci.yml must define an integration leg named "floor".' );

		[, $floor_php, $floor_wp, $floor_wc] = $floor;

		self::assertStringContainsString( $floor_php, $doc, "docs/COMPATIBILITY.md must mention the floor PHP version ({$floor_php})." );
		self::assertStringStartsWith( '6.5', $floor_wp, 'The floor leg must pin WordPress 6.5.x.' );
		self::assertStringContainsString( '8.2', $floor_wc, 'The floor leg must pin a WooCommerce 8.2.x release.' );
	}

	public function test_ceiling_leg_is_continue_on_error(): void {
		$ci = (string) file_get_contents( self::ROOT . '/.github/workflows/ci.yml' );

		self::assertStringContainsString( "continue-on-error: \${{ matrix.leg == 'ceiling' }}", $ci, 'The ceiling leg must never block a merge.' );
	}

	public function test_release_workflow_verifies_tag_header_parity(): void {
		$release = (string) file_get_contents( self::ROOT . '/.github/workflows/release.yml' );

		self::assertStringContainsString( 'Verify tag matches plugin version', $release );
	}
}
