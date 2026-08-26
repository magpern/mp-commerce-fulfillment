<?php
/**
 * Proves production fulfillment state transitions use WorkflowService::transition().
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
 * Path matrix for M3 lifecycle emission: admin UI, REST, wave services, and
 * refund/recovery all call `$this->workflow->transition()`. CLI has no writer.
 */
final class TransitionPathCoverageTest extends TestCase {

	/**
	 * @var list<string>
	 */
	private const ALLOWED_CALLERS = array(
		'Admin/FulfillmentDetailPage.php',
		'Admin/QueuePage.php',
		'Api/Rest/FulfillmentsController.php',
		'Application/Wave/WaveService.php',
		'Application/Wave/WaveScanService.php',
		'Woo/RefundObserver.php',
	);

	public function test_all_workflow_service_transition_callers_are_inventoried(): void {
		$found = $this->find_workflow_transition_callers( dirname( __DIR__, 2 ) . '/src' );

		sort( $found );
		$expected = self::ALLOWED_CALLERS;
		sort( $expected );

		self::assertSame(
			$expected,
			$found,
			'Unexpected or missing $this->workflow->transition() callers — update ALLOWED_CALLERS and M3 path matrix.'
		);
	}

	public function test_cli_has_no_workflow_transition_writer(): void {
		$found = $this->find_workflow_transition_callers( dirname( __DIR__, 2 ) . '/src/Cli' );

		self::assertSame( array(), $found );
	}

	public function test_hooks_document_lifecycle_action(): void {
		$hooks = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/HOOKS.md' );

		self::assertStringContainsString( 'mpcf_fulfillment_state_changed', $hooks );
		self::assertStringContainsString( 'record_events', $hooks );
	}

	/**
	 * @return list<string>
	 */
	private function find_workflow_transition_callers( string $root ): array {
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$src_prefix = dirname( __DIR__, 2 ) . '/src/';
		$found      = array();
		$iterator   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );
			if ( ! preg_match( '/\$this->workflow->transition\s*\(/', $contents ) ) {
				continue;
			}

			$relative = ltrim( str_replace( $src_prefix, '', $file->getPathname() ), '/' );
			$found[]  = $relative;
		}

		$found = array_values( array_unique( $found ) );
		sort( $found );

		return $found;
	}
}
