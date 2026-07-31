<?php
/**
 * Binds MPCF\PersistedKeys to docs/PERSISTED_DATA.md so the two can never
 * silently drift apart.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use MPCF\Capabilities;
use MPCF\Infrastructure\Database\Migrator;
use MPCF\Infrastructure\Database\Schema;
use MPCF\PersistedKeys;
use MPCF\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Persisted-keys documentation-sync test.
 */
final class PersistedKeysInventoryTest extends TestCase {

	public function test_inventory_matches_the_owning_classes(): void {
		$inventory = PersistedKeys::inventory();

		self::assertSame( array( Settings::OPTION, Migrator::OPTION ), $inventory['options'] );
		self::assertSame( Schema::all_tables(), $inventory['tables'] );
		self::assertSame( array(), $inventory['tables'], 'Milestone 0 introduces no business tables.' );
		self::assertSame( Capabilities::all(), $inventory['capabilities'] );
		self::assertSame( array( Capabilities::ROLE_OPERATOR, Capabilities::ROLE_LEAD ), $inventory['roles'] );
	}

	public function test_every_inventoried_key_is_documented_in_persisted_data_md(): void {
		$doc = (string) file_get_contents( __DIR__ . '/../../docs/PERSISTED_DATA.md' );

		foreach ( PersistedKeys::inventory() as $kind => $keys ) {
			foreach ( $keys as $key ) {
				self::assertStringContainsString(
					$key,
					$doc,
					"docs/PERSISTED_DATA.md must document the {$kind} entry `{$key}`."
				);
			}
		}
	}
}
