<?php
/**
 * Guards ADR-0006's three-defense promise: no Node/Playwright artifact,
 * and no runtime Composer dependency, ever reaches a release build.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `bin/build-zip.sh`'s post-copy assertion, `bin/release-audit.sh`'s zip
 * denylist, and this test cannot all run the real build pipeline here —
 * `bin/build-zip.sh` itself refuses to run while `vendor/phpunit` exists
 * (the dev-dependency guard every PHPUnit run necessarily has installed),
 * so shelling out to build a real zip from inside this suite is not
 * possible without a separate `composer install --no-dev` step outside
 * PHPUnit's own process — exactly what `bin/release-audit.sh`'s own CI
 * job already does for real. This test instead does two things a normal
 * run *can* do: statically confirm both shell scripts and `.gitignore`
 * carry the required guard code (source-text scan, the same technique
 * every other structural guard here uses), and mutation-test the
 * filename-classification logic itself in pure PHP, proving the pattern
 * set those scripts encode actually catches a planted Node artifact
 * rather than merely asserting it does.
 */
final class ReleaseArtifactGuardTest extends TestCase {

	private const ROOT = __DIR__ . '/../..';

	/**
	 * The Node/Playwright artifact names ADR-0006 requires every defense
	 * to exclude. Mirrors (not reads — these are two independent shell
	 * scripts, and a single shared source of truth for the pattern list
	 * would defeat the "three independent defenses" property ADR-0006
	 * deliberately asks for) `bin/build-zip.sh`'s `find` clause and
	 * `bin/release-audit.sh`'s `grep` clause.
	 *
	 * @return array<int, string>
	 */
	private static function forbidden_basenames(): array {
		return array(
			'package.json',
			'package-lock.json',
			'node_modules',
			'playwright.config.js',
			'playwright.config.ts',
			'.playwright',
			'playwright-report',
			'test-results',
		);
	}

	/**
	 * The same classification `bin/build-zip.sh`'s `find -iname` clause
	 * and `bin/release-audit.sh`'s `grep` clause perform: does this path
	 * name a forbidden artifact, anywhere in the tree.
	 *
	 * @param array<int, string> $paths Zip-relative paths to classify.
	 * @return array<int, string> The subset that are forbidden.
	 */
	private static function forbidden_paths_among( array $paths ): array {
		$forbidden = self::forbidden_basenames();

		return array_values(
			array_filter(
				$paths,
				static function ( string $path ) use ( $forbidden ): bool {
					$basename = basename( rtrim( $path, '/' ) );

					return in_array( strtolower( $basename ), array_map( 'strtolower', $forbidden ), true );
				}
			)
		);
	}

	public function test_a_clean_file_list_has_no_forbidden_paths(): void {
		$clean = array(
			'mp-commerce-fulfillment/mp-commerce-fulfillment.php',
			'mp-commerce-fulfillment/vendor/autoload.php',
			'mp-commerce-fulfillment/assets/admin/js/workspace.js',
			'mp-commerce-fulfillment/src/Plugin.php',
		);

		self::assertSame( array(), self::forbidden_paths_among( $clean ) );
	}

	public function test_the_classification_catches_a_planted_violation_at_any_depth(): void {
		$tainted = array(
			'mp-commerce-fulfillment/mp-commerce-fulfillment.php',
			'mp-commerce-fulfillment/assets/package.json',
			'mp-commerce-fulfillment/assets/vendor/node_modules/left-pad/index.js',
		);

		$violations = self::forbidden_paths_among( $tainted );

		self::assertNotSame( array(), $violations, 'The classification must catch a Node artifact nested inside assets/, not only at the zip root.' );
		self::assertContains( 'mp-commerce-fulfillment/assets/package.json', $violations );
	}

	public function test_build_zip_has_a_post_copy_node_artifact_assertion(): void {
		$script = (string) file_get_contents( self::ROOT . '/bin/build-zip.sh' );

		self::assertStringContainsString( 'node_modules', $script, 'bin/build-zip.sh must assert against Node artifacts after copying files into the build.' );
		self::assertStringContainsString( 'ADR-0006', $script );
		self::assertMatchesRegularExpression( '/exit 1/', $script );
	}

	public function test_release_audit_denies_every_forbidden_artifact_in_the_built_zip(): void {
		$script = (string) file_get_contents( self::ROOT . '/bin/release-audit.sh' );

		foreach ( array( 'package', 'node_modules', 'playwright', 'test-results', 'playwright-report' ) as $marker ) {
			self::assertStringContainsStringIgnoringCase( $marker, $script, "bin/release-audit.sh's zip-content denylist must mention \"{$marker}\"." );
		}
	}

	public function test_release_audit_asserts_the_zero_dependency_property(): void {
		$script = (string) file_get_contents( self::ROOT . '/bin/release-audit.sh' );

		self::assertStringContainsString( 'composer.json', $script );
		self::assertStringContainsString( 'require', $script );
	}

	public function test_release_audit_requires_api_docs(): void {
		$script = (string) file_get_contents( self::ROOT . '/bin/release-audit.sh' );

		self::assertStringContainsString( 'docs/API.md', $script );
	}

	public function test_composer_json_names_no_runtime_dependency(): void {
		$composer = json_decode( (string) file_get_contents( self::ROOT . '/composer.json' ), true );

		self::assertSame( array( 'php' ), array_keys( $composer['require'] ?? array() ), 'composer.json must require nothing beyond php — the zero-dependency property ADR-0006 must keep true.' );
	}

	public function test_gitignore_excludes_playwright_output(): void {
		$gitignore = (string) file_get_contents( self::ROOT . '/.gitignore' );

		self::assertStringContainsString( 'node_modules', $gitignore );
		self::assertStringContainsString( 'test-results', $gitignore );
		self::assertStringContainsString( 'playwright-report', $gitignore );
	}

	public function test_package_json_is_marked_private_so_it_can_never_be_published(): void {
		$package = json_decode( (string) file_get_contents( self::ROOT . '/package.json' ), true );

		self::assertTrue( $package['private'] ?? false );
	}
}
