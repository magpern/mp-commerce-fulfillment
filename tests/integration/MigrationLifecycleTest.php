<?php
/**
 * Proves the migration framework's per-step persistence, resume-after-
 * interruption, and idempotency, against a disposable fake step map, plus
 * the real Milestone 1 step against the real database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration;

use MPCF\Infrastructure\Database\Migrator;
use MPCF\Infrastructure\Database\Schema;
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

	public function test_real_migrator_creates_every_table_and_reaches_target_eight(): void {
		global $wpdb;

		foreach ( Schema::all_tables() as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		}

		$migrator = new Migrator();
		$migrator->migrate();

		self::assertSame( 8, $migrator->current_version() );
		self::assertSame( 8, (int) get_option( Migrator::OPTION ) );

		foreach ( Schema::all_tables() as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			self::assertNotNull( $exists, "{$table} must exist after the real migrator runs." );
		}
	}

	public function test_real_migrator_step_4_creates_the_shipping_tables(): void {
		global $wpdb;

		$migrator = new Migrator();
		$migrator->migrate();

		foreach ( array( Schema::SHIPMENTS, Schema::PACKAGES, Schema::PACKAGE_ITEMS ) as $unprefixed ) {
			$table  = Schema::table( $unprefixed );
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			self::assertNotNull( $exists, "{$table} must exist after the real migrator runs." );
		}
	}

	public function test_real_migrator_step_5_creates_the_documents_table(): void {
		global $wpdb;

		$migrator = new Migrator();
		$migrator->migrate();

		$table  = Schema::table( Schema::DOCUMENTS );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		self::assertNotNull( $exists, "{$table} must exist after the real migrator runs." );
	}

	public function test_real_migrator_step_2_adds_the_order_unique_index(): void {
		global $wpdb;

		$migrator = new Migrator();
		$migrator->migrate();

		$table = Schema::table( Schema::FULFILLMENTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
		$index = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", Schema::FULFILLMENTS_ORDER_UNIQUE_INDEX ) );

		self::assertNotNull( $index, 'The order-uniqueness index must exist after the real migrator runs.' );
	}

	public function test_real_migrator_step_3_adds_the_search_indexes(): void {
		global $wpdb;

		$migrator = new Migrator();
		$migrator->migrate();

		$fulfillments_table = Schema::table( Schema::FULFILLMENTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
		$name_index = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$fulfillments_table} WHERE Key_name = %s", Schema::FULFILLMENTS_CUSTOMER_NAME_INDEX ) );
		self::assertNotNull( $name_index, 'The customer-name-snapshot index must exist after the real migrator runs.' );

		$items_table = Schema::table( Schema::FULFILLMENT_ITEMS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
		$sku_index = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$items_table} WHERE Key_name = %s", Schema::FULFILLMENT_ITEMS_SKU_INDEX ) );
		self::assertNotNull( $sku_index, 'The sku-snapshot index must exist after the real migrator runs.' );
	}

	public function test_real_migrator_steps_are_idempotent_against_an_already_migrated_database(): void {
		$migrator = new Migrator();
		$migrator->migrate();

		// Re-running against a table/index that already exist (from the
		// previous test, or a prior activation) must not error — this is
		// exactly what the SHOW TABLES LIKE / SHOW INDEX guards in every
		// step exist to prove against a real database, not just the
		// unit-test double.
		$again = new Migrator();
		$again->migrate();

		self::assertSame( 8, $again->current_version() );
	}

	public function test_real_migrator_step_7_creates_the_wave_tables(): void {
		global $wpdb;

		$migrator = new Migrator();
		$migrator->migrate();

		foreach ( array( Schema::WAVES, Schema::WAVE_MEMBERS ) as $unprefixed ) {
			$table  = Schema::table( $unprefixed );
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			self::assertNotNull( $exists, "{$table} must exist after the real migrator runs." );
		}
	}

	public function test_real_migrator_step_8_creates_analytics_daily(): void {
		global $wpdb;

		$migrator = new Migrator();
		$migrator->migrate();

		$table  = Schema::table( Schema::ANALYTICS_DAILY );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		self::assertNotNull( $exists, "{$table} must exist after the real migrator runs." );
	}
}
