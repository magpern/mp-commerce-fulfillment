<?php
/**
 * Shared helper restoring a clean slate for this plugin's own tables
 * between integration tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration;

use MPCF\Infrastructure\Database\Migrator;
use MPCF\Infrastructure\Database\Schema;

/**
 * `WP_UnitTestCase` wraps each test in a transaction it rolls back
 * afterward — but that guarantee does not survive a DDL statement (MySQL
 * implicitly commits on `CREATE TABLE`/`ALTER TABLE`/`DROP TABLE`), and two
 * things this milestone's own tests do issue exactly that kind of
 * statement: `Migrator::migrate()` the first time a new step needs to run,
 * and `uninstall.php` (via `UninstallPolicyIntegrationTest`) when the
 * removal flag is enabled — which really does `DROP TABLE` every table
 * this plugin owns, permanently, for the rest of the PHPUnit process.
 * Neither is undone by a transaction rollback. Any test whose `setUp()`
 * assumes these tables exist and are empty can therefore find them either
 * dropped entirely or still holding rows a later test in the same process
 * did not expect (WordPress core's own tables are unaffected — they have
 * their own cleanup). This was harmless before D9 added a uniqueness
 * constraint on `mpcf_fulfillments`; it stopped being harmless the moment
 * a second insert of the same `(order_id, order_source)` became a hard
 * failure instead of a silently-tolerated duplicate, and a *dropped* table
 * is obviously fatal regardless.
 *
 * The fix is not architectural — production code runs `migrate()` exactly
 * once, on activation, and `uninstall.php` exactly once, on uninstall,
 * never both inside the same per-test transaction — it is this suite
 * explicitly restoring the guarantee the transaction can no longer make
 * for tables it owns.
 */
trait CleanFulfillmentTablesTrait {

	/**
	 * Guarantees every table this plugin owns exists and is empty. Call
	 * from `setUp()` in any test that inserts a fulfillment (or a child
	 * row) using a value — an order id, in particular — that another test
	 * in the same process also uses. Forces the recorded schema version
	 * back to 0 first so a fresh `migrate()` run recreates anything a
	 * prior test's real uninstall dropped, rather than trusting a version
	 * number that may no longer describe reality.
	 */
	private function clean_fulfillment_tables(): void {
		global $wpdb;

		delete_option( Migrator::OPTION );
		( new Migrator() )->migrate();

		foreach ( Schema::all_tables() as $table ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- $table is Schema-built, never user input; TRUNCATE cannot be parameterized.
		}
	}
}
