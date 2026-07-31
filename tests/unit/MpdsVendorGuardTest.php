<?php
/**
 * Guards the vendored MP Admin Design System copy against drift.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Recomputes the sha256 of every file listed in each vendored MANIFEST and
 * compares it to the committed hash. A hand-edit to src/Vendor/Mpds/ or
 * assets/mpds/ — instead of a re-run of bin/sync-mpds.sh against a fixed
 * upstream — fails this test (docs/CONSUMING.md in mp-admin-design-system:
 * fixes land upstream and are re-synced, never patched in place here).
 */
final class MpdsVendorGuardTest extends TestCase {

	private const ROOT = __DIR__ . '/../..';

	/**
	 * @return array<string, array<int, string>>
	 */
	public static function manifest_provider(): array {
		return array(
			'assets/mpds'     => array( self::ROOT . '/assets/mpds' ),
			'src/Vendor/Mpds' => array( self::ROOT . '/src/Vendor/Mpds' ),
		);
	}

	/**
	 * @dataProvider manifest_provider
	 */
	public function test_vendored_files_match_their_committed_manifest( string $base ): void {
		$manifest_path = $base . '/MANIFEST';

		self::assertFileExists( $manifest_path, "Expected a MANIFEST at {$manifest_path} — run bin/sync-mpds.sh." );

		$lines = array_filter( explode( "\n", (string) file_get_contents( $manifest_path ) ) );

		self::assertNotEmpty( $lines, 'MANIFEST must list at least one vendored file.' );

		foreach ( $lines as $line ) {
			[$expected_hash, $relative_path] = preg_split( '/\s{2,}/', trim( $line ), 2 );

			$full_path = $base . '/' . $relative_path;

			self::assertFileExists( $full_path, "MANIFEST references {$relative_path}, which does not exist." );

			$actual_hash = hash_file( 'sha256', $full_path );

			self::assertSame(
				$expected_hash,
				$actual_hash,
				"{$relative_path} does not match its committed manifest hash — hand-edited instead of re-synced from mp-admin-design-system?"
			);
		}
	}

	public function test_the_guard_itself_catches_a_hand_edit(): void {
		$fixture_file = sys_get_temp_dir() . '/mpcf-vendor-guard-fixture-' . uniqid() . '.css';
		file_put_contents( $fixture_file, 'original content' );
		$manifest_line = hash_file( 'sha256', $fixture_file ) . '  ' . basename( $fixture_file );

		// Simulate a hand-edit after the manifest was generated.
		file_put_contents( $fixture_file, 'hand-edited content' );

		[$expected_hash] = preg_split( '/\s{2,}/', $manifest_line, 2 );
		$actual_hash     = hash_file( 'sha256', $fixture_file );

		unlink( $fixture_file );

		self::assertNotSame( $expected_hash, $actual_hash, 'The hash comparison must detect a post-manifest edit.' );
	}
}
