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
	 * The complete inventory, keyed by kind.
	 *
	 * @return array<string, list<string>>
	 */
	public static function inventory(): array {
		return array(
			'options'      => self::option_keys(),
			'tables'       => self::tables(),
			'capabilities' => self::capabilities(),
			'roles'        => self::roles(),
		);
	}
}
