<?php
/**
 * Machine-readable inventory of everything this plugin persists.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF;

/**
 * Single source of truth for every option, table, capability and role this
 * plugin creates — the authoritative list `uninstall.php` removes when
 * `remove_data_on_uninstall` is enabled, and the list `PersistedKeysInventoryTest`
 * binds to `docs/PERSISTED_DATA.md` so the two can never silently drift
 * apart (house convention, copied from the sibling plugins' `PersistedKeys`).
 *
 * Milestone 0 has no tables yet — `tables()` is extended once
 * `Infrastructure\Database\Schema` exists.
 */
final class PersistedKeys {

	/**
	 * Every option this plugin owns.
	 *
	 * @return list<string>
	 */
	public static function option_keys(): array {
		return array( Settings::OPTION );
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
			'capabilities' => self::capabilities(),
			'roles'        => self::roles(),
		);
	}
}
