<?php
/**
 * Guards invariant I4: every transition flows through
 * WorkflowEngine::transition() via WorkflowService — the single writer of
 * `Fulfillment::apply_transition()`.
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
 * Single-state-writer guard. `Fulfillment::apply_transition()` is the only
 * Domain method that changes `state`/`previous_state`/`state_entered_at`
 * (see its own docblock); `Application\WorkflowService` is the only caller
 * this scan allows. `AssignmentService::assign()`/`unassign()` deliberately
 * persist a fulfillment without ever calling `apply_transition()` — that is
 * why this guard scans for the call token itself, not for
 * `FulfillmentRepository::save()` calls.
 */
final class SingleStateWriterGuardTest extends TestCase {

	private const ALLOWED_FILE = 'Application/WorkflowService.php';
	private const CALL_TOKEN   = '->apply_transition(';

	public function test_only_workflowservice_calls_apply_transition(): void {
		$violations = $this->scan( dirname( __DIR__, 2 ) . '/src' );

		self::assertSame( array(), $violations );
	}

	public function test_the_scan_itself_catches_a_second_caller(): void {
		$fixture_root = sys_get_temp_dir() . '/mpcf-single-state-writer-fixture-' . uniqid();
		$admin_dir    = $fixture_root . '/Admin';
		mkdir( $admin_dir, 0777, true );

		file_put_contents(
			$admin_dir . '/Tainted.php',
			"<?php\nnamespace MPCF\\Admin;\nfinal class Tainted {\n\tpublic function whatever( \$fulfillment ): void {\n\t\t\$fulfillment->apply_transition( 'x', null, new \\DateTimeImmutable() );\n\t}\n}\n"
		);

		$violations = $this->scan( $fixture_root );

		$this->remove_directory( $fixture_root );

		self::assertNotSame( array(), $violations, 'The scan must catch a second class calling apply_transition().' );
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

			if ( self::ALLOWED_FILE === $relative ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );

			if ( str_contains( $contents, self::CALL_TOKEN ) ) {
				$violations[] = $file->getPathname() . ' calls apply_transition() outside ' . self::ALLOWED_FILE;
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
