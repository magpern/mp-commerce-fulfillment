<?php
/**
 * Machine-readable inventory of everything this plugin persists.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF;

use MPCF\Infrastructure\Database\Migrator;
use MPCF\Infrastructure\Database\Schema;

/**
 * Single source of truth for every option, table, capability and role this
 * plugin creates — the authoritative list `uninstall.php` removes when
 * `remove_data_on_uninstall` is enabled, and the list `PersistedKeysInventoryTest`
 * binds to `docs/PERSISTED_DATA.md` so the two can never silently drift
 * apart (house convention, copied from the sibling plugins' `PersistedKeys`).
 */
final class PersistedKeys {

	/**
	 * Every option this plugin owns.
	 *
	 * @return list<string>
	 */
	public static function option_keys(): array {
		return array( Settings::OPTION, Migrator::OPTION );
	}

	/**
	 * Every table this plugin owns. Empty in Milestone 0.
	 *
	 * @return list<string>
	 */
	public static function tables(): array {
		return Schema::all_tables();
	}

	/**
	 * Every capability this plugin owns.
	 *
	 * @return list<string>
	 */
	public static function capabilities(): array {
		return Capabilities::all();
	}

	/**
	 * Every custom role this plugin creates.
	 *
	 * @return list<string>
	 */
	public static function roles(): array {
		return Capabilities::roles();
	}

	/**
	 * Every Action Scheduler group this plugin files its scheduled actions
	 * under — `Woo\IntakeHooks`'s `mpcf_process_intake` retry is the only
	 * one in Milestone 1. Removing this group's own scheduled rows on
	 * uninstall never touches Action Scheduler's own tables, which belong
	 * to the required order platform and are never dropped or altered by
	 * this plugin.
	 *
	 * @return list<string>
	 */
	public static function action_scheduler_groups(): array {
		return array( 'mpcf' );
	}

	/**
	 * Every user-meta key this plugin owns. Empty in Milestone 1: the
	 * architecture reserves `mpcf_ui_prefs` for saved Queue filter views
	 * (Architecture Plan Sec9.3), but that feature was never built — D15
	 * shipped the Queue without it, deliberately, to keep the milestone
	 * minimal. Declared here (rather than omitted) so the moment a future
	 * milestone introduces real user-meta, extending this list is the only
	 * change `uninstall.php` needs.
	 *
	 * @return list<string>
	 */
	public static function user_meta_keys(): array {
		return array();
	}

	/**
	 * The complete inventory, keyed by kind.
	 *
	 * @return array<string, list<string>>
	 */
	public static function inventory(): array {
		return array(
			'options'                 => self::option_keys(),
			'tables'                  => self::tables(),
			'capabilities'            => self::capabilities(),
			'roles'                   => self::roles(),
			'action_scheduler_groups' => self::action_scheduler_groups(),
			'user_meta'               => self::user_meta_keys(),
		);
	}
}
