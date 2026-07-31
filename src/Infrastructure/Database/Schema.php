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
 * (ADR-0001, following the sibling plugins' AIM ADR-0003 precedent).
 *
 * Milestone 0 introduces no business tables — `all_tables()` is empty until
 * Milestone 1 adds `mpcf_fulfillments`, `mpcf_fulfillment_items`,
 * `mpcf_events` and `mpcf_notes` (see docs/ARCHITECTURE_PLAN.md §7.1).
 */
final class Schema {

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
	 * Every table this plugin owns, in drop-safe order.
	 *
	 * @return list<string>
	 */
	public static function all_tables(): array {
		return array();
	}
}
