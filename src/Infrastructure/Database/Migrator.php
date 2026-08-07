<?php
/**
 * Versioned schema migrations.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

/**
 * Runs ordered, explicit SQL migration steps and records the schema version.
 *
 * The version lives in its own option (`mpcf_db_version`), separate from
 * `MPCF\Settings`, so a settings reset can never be mistaken for a schema
 * reset.
 *
 * Migrations run from two places: the activation hook, and an `admin_init`
 * drift check (`MPCF\Plugin::init()`). The second is not redundant — a
 * bind-mount deployment that updates files in place never fires the
 * activation hook, so without the drift check a schema upgrade would
 * silently never happen there (the same lesson the sibling plugins'
 * `Migrator` classes already encode).
 *
 * Every step is idempotent so a partially applied migration can be re-run
 * safely. The step map and target version are constructor-overridable
 * (defaulting to the real ones) purely so integration tests can exercise
 * per-step persistence, drift resume, and idempotency against a disposable
 * fake step map without needing Milestone 1's real tables to exist yet.
 */
final class Migrator {

	/**
	 * Option holding the applied schema version.
	 */
	public const OPTION = 'mpcf_db_version';

	/**
	 * Schema version this build expects. Milestone 1 raised this to 3:
	 * step 1 creates the four Milestone 1 tables, step 2 adds the
	 * intake-idempotency unique index, step 3 adds the two indexes
	 * SearchQuery v1 (D15) needs to keep its lookups indexed rather than
	 * full-table scans. Milestone 2 raises it to 5: step 4 creates the
	 * shipping tables (`mpcf_shipments`, `mpcf_packages`,
	 * `mpcf_package_items`, D19/ADR-0005), step 5 creates
	 * `mpcf_documents` (§10). M6 raises it to 6: step 6 creates
	 * `mpcf_media` (Part VIII package photography). M8 raises it to 7:
	 * step 7 creates `mpcf_waves` and `mpcf_wave_members` (Part X).
	 */
	public const TARGET = 7;

	/**
	 * Test-only step map override.
	 *
	 * @var array<int, callable():void>|null
	 */
	private ?array $steps_override;

	/**
	 * Test-only target-version override.
	 *
	 * @var int|null
	 */
	private ?int $target_override;

	/**
	 * Builds the migrator.
	 *
	 * @param array<int, callable():void>|null $steps_override Overrides {@see steps()}, for tests.
	 * @param int|null                         $target_override Overrides {@see TARGET}, for tests.
	 */
	public function __construct( ?array $steps_override = null, ?int $target_override = null ) {
		$this->steps_override  = $steps_override;
		$this->target_override = $target_override;
	}

	/**
	 * Applies any migration steps newer than the recorded version.
	 *
	 * The version is written after each individual step, so an interrupted
	 * run resumes from the step that failed rather than replaying from zero.
	 * The target version is written unconditionally at the end so the
	 * option always exists and reflects reality, even when there are no
	 * steps to run at all (Milestone 0).
	 */
	public function migrate(): void {
		$current = $this->current_version();

		foreach ( $this->steps() as $version => $step ) {
			if ( $version <= $current ) {
				continue;
			}

			$step();

			update_option( self::OPTION, $version, true );
		}

		update_option( self::OPTION, $this->target(), true );
	}

	/**
	 * Runs migrations only when the recorded version is behind this build's
	 * target.
	 */
	public function maybe_migrate(): void {
		if ( $this->current_version() >= $this->target() ) {
			return;
		}

		$this->migrate();
	}

	/**
	 * Schema version currently applied to the database.
	 */
	public function current_version(): int {
		return (int) get_option( self::OPTION, 0 );
	}

	/**
	 * The version this instance migrates toward.
	 */
	private function target(): int {
		return $this->target_override ?? self::TARGET;
	}

	/**
	 * Ordered migration steps, keyed by the version they produce.
	 *
	 * @return array<int, callable():void>
	 */
	private function steps(): array {
		return $this->steps_override ?? array(
			1 => array( $this, 'step_1_initial_tables' ),
			2 => array( $this, 'step_2_order_unique_index' ),
			3 => array( $this, 'step_3_search_indexes' ),
			4 => array( $this, 'step_4_shipping_tables' ),
			5 => array( $this, 'step_5_documents_table' ),
			6 => array( $this, 'step_6_media_table' ),
			7 => array( $this, 'step_7_wave_tables' ),
		);
	}

	/**
	 * Milestone 1: creates `mpcf_fulfillments`, `mpcf_fulfillment_items`,
	 * `mpcf_events` and `mpcf_notes` ({@see Schema::create_statements()}).
	 * Idempotent: `CREATE TABLE` (no `IF NOT EXISTS`) would error on re-run,
	 * so a table that already exists is skipped by checking
	 * `SHOW TABLES LIKE` first — the same re-run safety every other step in
	 * this class provides, extended to raw DDL.
	 */
	private function step_1_initial_tables(): void {
		global $wpdb;

		foreach ( Schema::create_statements() as $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL from Schema, no user input.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $this->table_name_from_ddl( $sql ) ) ) );

			if ( null !== $exists ) {
				continue;
			}

			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL from Schema, no user input.
		}
	}

	/**
	 * Extracts the table name a `CREATE TABLE {name} (...)` statement
	 * targets, for the idempotency check in {@see step_1_initial_tables()}.
	 *
	 * @param string $create_table_sql A `CREATE TABLE` statement produced by
	 *                                 {@see Schema::create_statements()}.
	 */
	private function table_name_from_ddl( string $create_table_sql ): string {
		preg_match( '/CREATE TABLE (\S+)\s*\(/', $create_table_sql, $matches );

		return $matches[1] ?? '';
	}

	/**
	 * Adds the unique index intake idempotency depends on
	 * ({@see Schema::fulfillments_order_unique_index_ddl()}). Idempotent:
	 * checked via `SHOW INDEX` before adding, the same re-run safety
	 * {@see step_1_initial_tables()} applies to its `CREATE TABLE`s.
	 */
	private function step_2_order_unique_index(): void {
		global $wpdb;

		$table = Schema::table( Schema::FULFILLMENTS );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema, no user input; only the index name is a %s placeholder.
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", Schema::FULFILLMENTS_ORDER_UNIQUE_INDEX ) );

		if ( null !== $exists ) {
			return;
		}

		$wpdb->query( Schema::fulfillments_order_unique_index_ddl() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL from Schema, no user input.
	}

	/**
	 * Adds the two indexes {@see \MPCF\Domain\SearchQuery} v1 (D15) needs to
	 * keep its customer-name and SKU lookups indexed. Idempotent, same
	 * `SHOW INDEX` pattern as {@see step_2_order_unique_index()}.
	 */
	private function step_3_search_indexes(): void {
		global $wpdb;

		$fulfillments_table = Schema::table( Schema::FULFILLMENTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema, no user input; only the index name is a %s placeholder.
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$fulfillments_table} WHERE Key_name = %s", Schema::FULFILLMENTS_CUSTOMER_NAME_INDEX ) );

		if ( null === $exists ) {
			$wpdb->query( Schema::fulfillments_customer_name_index_ddl() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL from Schema, no user input.
		}

		$items_table = Schema::table( Schema::FULFILLMENT_ITEMS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema, no user input; only the index name is a %s placeholder.
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$items_table} WHERE Key_name = %s", Schema::FULFILLMENT_ITEMS_SKU_INDEX ) );

		if ( null === $exists ) {
			$wpdb->query( Schema::fulfillment_items_sku_index_ddl() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL from Schema, no user input.
		}
	}

	/**
	 * Milestone 2: creates `mpcf_shipments`, `mpcf_packages` and
	 * `mpcf_package_items` (D19/ADR-0005). Idempotent via the same
	 * `SHOW TABLES LIKE` guard {@see step_1_initial_tables()} uses.
	 */
	private function step_4_shipping_tables(): void {
		global $wpdb;

		foreach ( Schema::shipping_create_statements() as $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL from Schema, no user input.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $this->table_name_from_ddl( $sql ) ) ) );

			if ( null !== $exists ) {
				continue;
			}

			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL from Schema, no user input.
		}
	}

	/**
	 * Milestone 2: creates `mpcf_documents` (§10). Idempotent via the same
	 * `SHOW TABLES LIKE` guard {@see step_1_initial_tables()} uses.
	 */
	private function step_5_documents_table(): void {
		global $wpdb;

		foreach ( Schema::documents_create_statements() as $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL from Schema, no user input.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $this->table_name_from_ddl( $sql ) ) ) );

			if ( null !== $exists ) {
				continue;
			}

			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL from Schema, no user input.
		}
	}

	/**
	 * M6: creates `mpcf_media` (Part VIII package photography). Idempotent
	 * via the same `SHOW TABLES LIKE` guard {@see step_1_initial_tables()}
	 * uses.
	 */
	private function step_6_media_table(): void {
		global $wpdb;

		foreach ( Schema::media_create_statements() as $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL from Schema, no user input.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $this->table_name_from_ddl( $sql ) ) ) );

			if ( null !== $exists ) {
				continue;
			}

			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL from Schema, no user input.
		}
	}

	/**
	 * M8: creates `mpcf_waves` and `mpcf_wave_members` (Part X). Idempotent
	 * via the same `SHOW TABLES LIKE` guard {@see step_1_initial_tables()}
	 * uses.
	 */
	private function step_7_wave_tables(): void {
		global $wpdb;

		foreach ( Schema::wave_create_statements() as $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL from Schema, no user input.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $this->table_name_from_ddl( $sql ) ) ) );

			if ( null !== $exists ) {
				continue;
			}

			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL from Schema, no user input.
		}
	}
}
