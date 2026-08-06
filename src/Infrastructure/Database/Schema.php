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
	 * Unprefixed table name for the shipment (carrier handover) aggregate,
	 * added by {@see \MPCF\Infrastructure\Database\Migrator}'s step 4.
	 */
	public const SHIPMENTS = 'mpcf_shipments';

	/**
	 * Unprefixed table name for physical packages within a shipment
	 * (ADR-0005), added by step 4.
	 */
	public const PACKAGES = 'mpcf_packages';

	/**
	 * Unprefixed table name for per-package line-quantity allocations,
	 * added by step 4.
	 */
	public const PACKAGE_ITEMS = 'mpcf_package_items';

	/**
	 * Unprefixed table name for package photography evidence (Part VIII),
	 * added by step 6.
	 */
	public const MEDIA = 'mpcf_media';

	/**
	 * Unprefixed table name for the document generation record (§10),
	 * added by step 5.
	 */
	public const DOCUMENTS = 'mpcf_documents';

	/**
	 * Unprefixed table name for wave aggregates (Part X / M8), added by
	 * step 7.
	 */
	public const WAVES = 'mpcf_waves';

	/**
	 * Unprefixed table name for wave memberships (Part X / M8), added by
	 * step 7.
	 */
	public const WAVE_MEMBERS = 'mpcf_wave_members';

	/**
	 * Name of the unique index enforcing intake idempotency at the database
	 * level (added by {@see \MPCF\Infrastructure\Database\Migrator}'s
	 * second step — see {@see fulfillments_order_unique_index_ddl()}).
	 */
	public const FULFILLMENTS_ORDER_UNIQUE_INDEX = 'order_unique';

	/**
	 * Name of the index {@see \MPCF\Domain\SearchQuery} v1's name-snapshot lookup needs
	 * to stay a prefix-indexed scan instead of an unindexed one.
	 */
	public const FULFILLMENTS_CUSTOMER_NAME_INDEX = 'customer_name_snapshot';

	/**
	 * Name of the index {@see \MPCF\Domain\SearchQuery} v1's SKU lookup needs.
	 */
	public const FULFILLMENT_ITEMS_SKU_INDEX = 'sku_snapshot';

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
			self::table( self::WAVE_MEMBERS ),
			self::table( self::WAVES ),
			self::table( self::NOTES ),
			self::table( self::EVENTS ),
			self::table( self::PACKAGE_ITEMS ),
			self::table( self::MEDIA ),
			self::table( self::PACKAGES ),
			self::table( self::SHIPMENTS ),
			self::table( self::DOCUMENTS ),
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
	 * `ALTER TABLE` statement adding the index {@see \MPCF\Domain\SearchQuery} v1's
	 * customer-name lookup needs. Added post-tag-freeze-prep (D15/D22's own
	 * "verified against real Queue query shapes" clause at the top of this
	 * file) rather than folded into {@see fulfillments_ddl()}, for the same
	 * reason {@see fulfillments_order_unique_index_ddl()} is its own step.
	 */
	public static function fulfillments_customer_name_index_ddl(): string {
		$table = self::table( self::FULFILLMENTS );
		$index = self::FULFILLMENTS_CUSTOMER_NAME_INDEX;

		return "ALTER TABLE {$table} ADD KEY {$index} (customer_name_snapshot)";
	}

	/**
	 * `ALTER TABLE` statement adding the index {@see \MPCF\Domain\SearchQuery} v1's SKU
	 * lookup needs.
	 */
	public static function fulfillment_items_sku_index_ddl(): string {
		$table = self::table( self::FULFILLMENT_ITEMS );
		$index = self::FULFILLMENT_ITEMS_SKU_INDEX;

		return "ALTER TABLE {$table} ADD KEY {$index} (sku_snapshot)";
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
	 * `CREATE TABLE` statements for Milestone 2's shipping tables
	 * (Migrator step 4), in creation-safe order.
	 *
	 * @return list<string>
	 */
	public static function shipping_create_statements(): array {
		return array(
			self::shipments_ddl(),
			self::packages_ddl(),
			self::package_items_ddl(),
		);
	}

	/**
	 * `CREATE TABLE` statement for Milestone 2's document record table
	 * (Migrator step 5).
	 *
	 * @return list<string>
	 */
	public static function documents_create_statements(): array {
		return array(
			self::documents_ddl(),
		);
	}

	/**
	 * `CREATE TABLE` statement for M6 package photography (Migrator step 6).
	 *
	 * @return list<string>
	 */
	public static function media_create_statements(): array {
		return array(
			self::media_ddl(),
		);
	}

	/**
	 * `CREATE TABLE` statements for M8 wave tables (Migrator step 7), in
	 * creation-safe order (waves before members).
	 *
	 * @return list<string>
	 */
	public static function wave_create_statements(): array {
		return array(
			self::waves_ddl(),
			self::wave_members_ddl(),
		);
	}

	/**
	 * DDL for `mpcf_shipments` — the consignment (one carrier handover).
	 * Architecture Plan §IV.6: indexed on `fulfillment_id` (the workspace's
	 * only lookup path), `status` (a future tracking-sync sweep), and
	 * `tracking_number` (D22's search target — a scanned or pasted tracking
	 * number must resolve without an unindexed scan).
	 */
	private static function shipments_ddl(): string {
		$table           = self::table( self::SHIPMENTS );
		$charset_collate = self::charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			fulfillment_id BIGINT UNSIGNED NOT NULL,
			carrier_id VARCHAR(64) NOT NULL,
			service VARCHAR(128) NULL,
			tracking_number VARCHAR(191) NULL,
			tracking_url TEXT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			shipped_at DATETIME NULL,
			delivered_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY fulfillment_id (fulfillment_id),
			KEY status (status),
			KEY tracking_number (tracking_number)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC {$charset_collate};";
	}

	/**
	 * DDL for `mpcf_packages` — physical boxes within a shipment (ADR-0005,
	 * D19). `label_path` is created now and left NULL until M12; adding the
	 * column later would mean an `ALTER TABLE` on a table that will be
	 * large by then.
	 */
	private static function packages_ddl(): string {
		$table           = self::table( self::PACKAGES );
		$charset_collate = self::charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			shipment_id BIGINT UNSIGNED NOT NULL,
			seq SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			weight_grams INT UNSIGNED NULL,
			length_mm INT UNSIGNED NULL,
			width_mm INT UNSIGNED NULL,
			height_mm INT UNSIGNED NULL,
			tracking_number VARCHAR(191) NULL,
			label_path VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY shipment_id (shipment_id)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC {$charset_collate};";
	}

	/**
	 * DDL for `mpcf_package_items` — per-package line-quantity
	 * allocations. Milestone 2 always allocates every packed line to
	 * package 1 (PO decision, Architecture Plan §IV.0.2); the table shape
	 * already supports the M4 line-allocation split with no schema change.
	 */
	private static function package_items_ddl(): string {
		$table           = self::table( self::PACKAGE_ITEMS );
		$charset_collate = self::charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			package_id BIGINT UNSIGNED NOT NULL,
			fulfillment_item_id BIGINT UNSIGNED NOT NULL,
			qty SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY package_id (package_id),
			KEY fulfillment_item_id (fulfillment_item_id)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC {$charset_collate};";
	}

	/**
	 * DDL for `mpcf_media` — package photography evidence (Part VIII).
	 * Soft-delete via `deleted_at`; retention purge (M6-D) sets `purged_at`
	 * and clears bytes while preserving metadata. Relative paths only.
	 */
	private static function media_ddl(): string {
		$table           = self::table( self::MEDIA );
		$charset_collate = self::charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			fulfillment_id BIGINT UNSIGNED NOT NULL,
			package_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(32) NOT NULL,
			file_path VARCHAR(255) NOT NULL,
			thumb_path VARCHAR(255) NOT NULL,
			mime VARCHAR(64) NOT NULL,
			bytes INT UNSIGNED NOT NULL,
			sha256 CHAR(64) NOT NULL,
			processing_version SMALLINT UNSIGNED NOT NULL,
			width INT UNSIGNED NOT NULL,
			height INT UNSIGNED NOT NULL,
			seq INT UNSIGNED NOT NULL,
			captured_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			purged_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY fulfillment_id (fulfillment_id),
			KEY package_id (package_id),
			KEY fulfillment_deleted (fulfillment_id, deleted_at),
			KEY package_deleted (package_id, deleted_at),
			KEY fulfillment_seq (fulfillment_id, seq)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC {$charset_collate};";
	}

	/**
	 * DDL for `mpcf_documents` — the document generation record (§10).
	 * `file_path` stays NULL for a rendered-to-print document (Milestone
	 * 2's packing slip never stores a file); a future milestone's stored
	 * renders populate it.
	 */
	private static function documents_ddl(): string {
		$table           = self::table( self::DOCUMENTS );
		$charset_collate = self::charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			fulfillment_id BIGINT UNSIGNED NOT NULL,
			doc_type VARCHAR(64) NOT NULL,
			template_version VARCHAR(32) NOT NULL,
			file_path VARCHAR(255) NULL,
			rendered_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY fulfillment_id (fulfillment_id),
			KEY doc_type (doc_type)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC {$charset_collate};";
	}

	/**
	 * DDL for `mpcf_waves` — multi-fulfillment warehouse walk aggregate
	 * (Architecture Plan Part X.2 / X.12).
	 */
	private static function waves_ddl(): string {
		$table           = self::table( self::WAVES );
		$charset_collate = self::charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			warehouse_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
			owner_user_id BIGINT UNSIGNED NULL,
			state VARCHAR(32) NOT NULL,
			version INT UNSIGNED NOT NULL DEFAULT 1,
			title VARCHAR(191) NOT NULL DEFAULT '',
			settings_snapshot LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			activated_at DATETIME NULL,
			completed_at DATETIME NULL,
			abandoned_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY state_warehouse_owner (state, warehouse_id, owner_user_id),
			KEY updated_at (updated_at)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC {$charset_collate};";
	}

	/**
	 * DDL for `mpcf_wave_members` — exclusive fulfillment membership in a
	 * wave. Open-wave uniqueness is enforced in Application (MySQL lacks
	 * portable partial unique indexes here).
	 */
	private static function wave_members_ddl(): string {
		$table           = self::table( self::WAVE_MEMBERS );
		$charset_collate = self::charset_collate();

		return "CREATE TABLE {$table} (
			wave_id BIGINT UNSIGNED NOT NULL,
			fulfillment_id BIGINT UNSIGNED NOT NULL,
			position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			joined_at DATETIME NOT NULL,
			picked_at DATETIME NULL,
			PRIMARY KEY  (wave_id, fulfillment_id),
			KEY fulfillment_id (fulfillment_id)
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
