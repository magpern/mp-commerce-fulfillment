<?php
/**
 * Proves the migration framework's per-step persistence, resume-after-
 * interruption, and idempotency, against a disposable fake step map
 * (Milestone 0 ships no real migration steps yet).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration;

use MPCF\Infrastructure\Database\Migrator;
use RuntimeException;
use WP_UnitTestCase;

/**
 * Migration framework lifecycle tests.
 */
final class MigrationLifecycleTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		delete_option( Migrator::OPTION );
	}

	public function test_version_persists_after_each_step_and_resumes_after_interruption(): void {
		$log = array();

		$steps = array(
			1 => static function () use ( &$log ) {
				$log[] = 1;
			},
			2 => static function () use ( &$log ) {
				$log[] = 2;
				throw new RuntimeException( 'simulated interruption' );
			},
			3 => static function () use ( &$log ) {
				$log[] = 3;
			},
		);

		$migrator = new Migrator( $steps, 3 );

		try {
			$migrator->migrate();
			self::fail( 'Expected the simulated interruption to propagate.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'simulated interruption', $exception->getMessage() );
		}

		self::assertSame( array( 1, 2 ), $log, 'Step 1 must complete and step 2 must have started before the interruption.' );
		self::assertSame( 1, $migrator->current_version(), 'Only step 1 fully completed before failing — the recorded version must be 1, not 2.' );

		// Resume: step 2 no longer throws. A fresh Migrator instance mirrors
		// the real-world case (a new request after the interrupted one).
		$log      = array();
		$steps[2] = static function () use ( &$log ) {
			$log[] = 2;
		};
		$resumed  = new Migrator( $steps, 3 );
		$resumed->migrate();

		self::assertSame( array( 2, 3 ), $log, 'Resume must re-run step 2 (never completed) then step 3, but never replay step 1.' );
		self::assertSame( 3, $resumed->current_version() );
	}

	public function test_migrate_does_not_replay_steps_once_at_target(): void {
		$log   = array();
		$steps = array(
			1 => static function () use ( &$log ) {
				$log[] = 1;
			},
		);

		$migrator = new Migrator( $steps, 1 );
		$migrator->migrate();
		$migrator->migrate();

		self::assertSame( array( 1 ), $log, 'Re-running migrate() after reaching target must not replay any step.' );
	}

	public function test_maybe_migrate_only_runs_when_behind_target(): void {
		$calls = 0;
		$steps = array(
			1 => static function () use ( &$calls ) {
				++$calls;
			},
		);

		$migrator = new Migrator( $steps, 1 );

		$migrator->maybe_migrate();
		self::assertSame( 1, $calls );

		$migrator->maybe_migrate();
		self::assertSame( 1, $calls, 'maybe_migrate() must no-op once current_version() is already at target.' );
	}

	public function test_real_milestone_0_migrator_writes_target_zero_with_no_steps(): void {
		$migrator = new Migrator();
		$migrator->migrate();

		self::assertSame( 0, $migrator->current_version() );
		self::assertSame( 0, (int) get_option( Migrator::OPTION ) );
	}
}
