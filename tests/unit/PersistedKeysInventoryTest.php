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
		self::assertCount( 4, $inventory['tables'], 'Milestone 1 introduces the four fulfillment tables.' );
		self::assertSame( Capabilities::all(), $inventory['capabilities'] );
		self::assertSame( array( Capabilities::ROLE_OPERATOR, Capabilities::ROLE_LEAD ), $inventory['roles'] );
		self::assertSame( array( 'mpcf' ), $inventory['action_scheduler_groups'] );
		self::assertSame( array(), $inventory['user_meta'], 'Milestone 1 never wrote any user meta — see PersistedKeys::user_meta_keys().' );
	}

	public function test_every_inventoried_key_is_documented_in_persisted_data_md(): void {
		$doc = (string) file_get_contents( __DIR__ . '/../../docs/PERSISTED_DATA.md' );

		foreach ( PersistedKeys::inventory() as $kind => $keys ) {
			foreach ( $keys as $key ) {
				// Table names are runtime-prefixed ({$wpdb->prefix}mpcf_*);
				// the doc documents the unprefixed name, matching how
				// CLAUDE.md itself describes table naming generically.
				$needle = 'tables' === $kind ? self::unprefixed_table_name( $key ) : $key;

				self::assertStringContainsString(
					$needle,
					$doc,
					"docs/PERSISTED_DATA.md must document the {$kind} entry `{$needle}`."
				);
			}
		}
	}

	private static function unprefixed_table_name( string $table ): string {
		global $wpdb;

		return str_starts_with( $table, $wpdb->prefix )
			? substr( $table, strlen( $wpdb->prefix ) )
			: $table;
	}
}
