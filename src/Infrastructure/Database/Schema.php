<?php
/**
 * Table names and DDL.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

/**
 * Single source of truth for this plugin's table names and their DDL.
 *
 * Every name is built from `$wpdb->prefix`; no table name is ever hardcoded
 * with a `wp_` prefix. DDL is written out explicitly rather than handed to
 * `dbDelta()` — dbDelta's parser silently drops composite prefix indexes
 * (ADR-0001, following the sibling plugins' AIM ADR-0003 precedent). No SQL
 * `ENUM` (state columns are `VARCHAR` + PHP constants — house convention);
 * no `FOREIGN KEY` constraints (app-level integrity + indexes instead, same
 * house convention followed elsewhere in this plugin family); every table
 * is `ENGINE=InnoDB ROW_FORMAT=DYNAMIC` with `BIGINT UNSIGNED
 * AUTO_INCREMENT` ids and UTC `DATETIME` columns.
 *
 * Milestone 1 (Architecture Plan §7.1) introduces the first four business
 * tables. Column shapes, including the pre-specified indexes, are frozen by
 * the architecture doc and verified against real Queue query shapes and a
 * 10k-row `EXPLAIN` proof before the `v0.1.0` tag (Part III §III.2.2) — if
 * that proof finds a missing index, this file is amended before tagging,
 * not patched afterward.
 */
final class Schema {

	/**
	 * Unprefixed table name for the fulfillment aggregate root.
	 */
	public const FULFILLMENTS = 'mpcf_fulfillments';

	/**
	 * Unprefixed table name for fulfillment line items.
	 */
	public const FULFILLMENT_ITEMS = 'mpcf_fulfillment_items';

	/**
	 * Unprefixed table name for the append-only, hash-chained audit log.
	 */
	public const EVENTS = 'mpcf_events';

	/**
	 * Unprefixed table name for internal fulfillment notes.
	 */
	public const NOTES = 'mpcf_notes';

	/**
	 * Name of the unique index enforcing intake idempotency at the database
	 * level (added by {@see \MPCF\Infrastructure\Database\Migrator}'s
	 * second step — see {@see fulfillments_order_unique_index_ddl()}).
	 */
	public const FULFILLMENTS_ORDER_UNIQUE_INDEX = 'order_unique';

	/**
	 * Prefixes a table name with the current site's table prefix.
	 *
	 * @param string $name Unprefixed table name.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . $name;
	}

	/**
	 * Every table this plugin owns, in drop-safe order (children before the
	 * aggregate root they reference by id — no FK constraints exist, but the
	 * order keeps uninstall's intent legible).
	 *
	 * @return list<string>
	 */
	public static function all_tables(): array {
		return array(
			self::table( self::NOTES ),
			self::table( self::EVENTS ),
			self::table( self::FULFILLMENT_ITEMS ),
			self::table( self::FULFILLMENTS ),
		);
	}

	/**
	 * `CREATE TABLE` statements for every Milestone 1 table, in
	 * creation-safe order (the aggregate root first).
	 *
	 * @return list<string>
	 */
	public static function create_statements(): array {
		return array(
			self::fulfillments_ddl(),
			self::fulfillment_items_ddl(),
			self::events_ddl(),
			self::notes_ddl(),
		);
	}

	/**
	 * DDL for `mpcf_fulfillments` — the aggregate root (Architecture Plan
	 * §7.1). Indexes: `(state, warehouse_id)` for the Queue's default
	 * open-work filter, `(order_id)` for intake idempotency lookups,
	 * `(assignee_type, assignee_id, state)` for "my queue" views,
	 * `(created_at)` for oldest-first ordering.
	 */
	private static function fulfillments_ddl(): string {
		$table           = self::table( self::FULFILLMENTS );
		$charset_collate = self::charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			order_source VARCHAR(32) NOT NULL DEFAULT 'woocommerce',
			warehouse_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
			workflow VARCHAR(64) NOT NULL,
			state VARCHAR(32) NOT NULL,
			previous_state VARCHAR(32) NULL,
			return_to_state VARCHAR(32) NULL,
			exception_reason VARCHAR(191) NULL,
			priority SMALLINT NOT NULL DEFAULT 0,
			assignee_type VARCHAR(16) NULL,
			assignee_id BIGINT UNSIGNED NULL,
			version INT UNSIGNED NOT NULL DEFAULT 1,
			order_number_snapshot VARCHAR(64) NOT NULL DEFAULT '',
			customer_name_snapshot VARCHAR(191) NOT NULL DEFAULT '',
			item_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			state_entered_at DATETIME NOT NULL,
			completed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY state_warehouse (state, warehouse_id),
			KEY order_id (order_id),
			KEY assignee_state (assignee_type, assignee_id, state),
			KEY created_at (created_at)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC {$charset_collate};";
	}

	/**
	 * `ALTER TABLE` statement adding the unique index that makes intake
	 * idempotency a database-enforced guarantee, not only an
	 * application-level check-then-insert. Added as a separate migration
	 * step ({@see \MPCF\Infrastructure\Database\Migrator}) rather than
	 * folded into {@see fulfillments_ddl()} — this schema had already been
	 * applied to a running database (this milestone's own dev/test
	 * environments) by the time intake's idempotency requirement (D9) made
	 * the gap concrete, and step-based migration is exactly the mechanism
	 * this framework has for that, pre-tag or not.
	 *
	 * One order can have more than one fulfillment in the aggregate's own
	 * general design (a future multi-warehouse split, for instance) — this
	 * index does not foreclose that permanently, it enforces the Milestone
	 * 1 reality that intake creates exactly one fulfillment per order. A
	 * milestone that introduces real per-order splitting will need its own
	 * ADR to relax or replace this constraint; that is a deliberate future
	 * decision, not an oversight now.
	 */
	public static function fulfillments_order_unique_index_ddl(): string {
		$table = self::table( self::FULFILLMENTS );
		$index = self::FULFILLMENTS_ORDER_UNIQUE_INDEX;

		return "ALTER TABLE {$table} ADD UNIQUE KEY {$index} (order_id, order_source)";
	}

	/**
	 * DDL for `mpcf_fulfillment_items` — line items, snapshotted so picking
	 * lists and audit stay stable even if the product is later renamed or
	 * deleted.
	 */
	private static function fulfillment_items_ddl(): string {
		$table           = self::table( self::FULFILLMENT_ITEMS );
		$charset_collate = self::charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			fulfillment_id BIGINT UNSIGNED NOT NULL,
			order_item_id BIGINT UNSIGNED NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			sku_snapshot VARCHAR(191) NOT NULL DEFAULT '',
			name_snapshot VARCHAR(255) NOT NULL DEFAULT '',
			qty_ordered SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			qty_picked SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			qty_packed SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			location_snapshot VARCHAR(191) NULL,
			PRIMARY KEY  (id),
			KEY fulfillment_id (fulfillment_id)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC {$charset_collate};";
	}

	/**
	 * DDL for `mpcf_events` — the append-only (I5), hash-chained audit log.
	 * `fulfillment_id` is nullable for global (non-fulfillment-scoped)
	 * events.
	 */
	private static function events_ddl(): string {
		$table           = self::table( self::EVENTS );
		$charset_collate = self::charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			fulfillment_id BIGINT UNSIGNED NULL,
			event_type VARCHAR(64) NOT NULL,
			actor_type VARCHAR(16) NOT NULL,
			actor_id BIGINT UNSIGNED NULL,
			actor_label_snapshot VARCHAR(191) NOT NULL DEFAULT '',
			payload LONGTEXT NOT NULL,
			prev_hash CHAR(64) NULL,
			hash CHAR(64) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY fulfillment_id (fulfillment_id),
			KEY event_type (event_type),
			KEY created_at (created_at)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC {$charset_collate};";
	}

	/**
	 * DDL for `mpcf_notes` — internal fulfillment notes.
	 */
	private static function notes_ddl(): string {
		$table           = self::table( self::NOTES );
		$charset_collate = self::charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			fulfillment_id BIGINT UNSIGNED NOT NULL,
			author_id BIGINT UNSIGNED NOT NULL,
			body TEXT NOT NULL,
			is_pinned TINYINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY fulfillment_id (fulfillment_id)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC {$charset_collate};";
	}

	/**
	 * The site's charset/collation clause for `CREATE TABLE` statements.
	 */
	private static function charset_collate(): string {
		global $wpdb;

		return $wpdb->get_charset_collate();
	}
}
