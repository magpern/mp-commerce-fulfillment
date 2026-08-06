<?php
/**
 * Activation lifecycle integration tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration;

use MPCF\Capabilities;
use MPCF\Infrastructure\Database\Migrator;
use MPCF\Infrastructure\Database\Schema;
use MPCF\Plugin;
use WP_UnitTestCase;

/**
 * Real-WordPress activation tests.
 *
 * Milestone 0's version of this class included a test asserting the
 * plugin registered no `woocommerce_*` callback anywhere — true only
 * because nothing here needed one yet. Milestone 1 deliberately registers
 * real intake hooks (see Woo\IntakeHooksTest), so that assertion's premise
 * no longer holds and the test was removed rather than kept as a check for
 * the absence of intended behavior.
 */
final class ActivationTest extends WP_UnitTestCase {

	public function test_activation_creates_every_table(): void {
		global $wpdb;

		Plugin::activate();

		self::assertSame( 6, (int) get_option( Migrator::OPTION ), 'mpcf_db_version must reach target 6.' );

		foreach ( Schema::all_tables() as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			self::assertNotNull( $exists, "{$table} must exist after activation." );
		}

		self::assertCount( 8, Schema::all_tables() );
	}

	public function test_activation_grants_roles_and_capabilities(): void {
		Plugin::activate();

		$operator = get_role( Capabilities::ROLE_OPERATOR );
		$lead     = get_role( Capabilities::ROLE_LEAD );

		self::assertNotNull( $operator );
		self::assertNotNull( $lead );

		foreach ( Capabilities::all() as $capability ) {
			self::assertTrue( $lead->has_cap( $capability ) );
		}

		self::assertFalse( $operator->has_cap( Capabilities::MANAGE_SETTINGS ) );

		$administrator = get_role( 'administrator' );
		foreach ( Capabilities::all() as $capability ) {
			self::assertTrue( $administrator->has_cap( $capability ), "administrator must hold {$capability}." );
		}
	}

	public function test_reactivation_is_idempotent(): void {
		Plugin::activate();
		Plugin::activate();

		self::assertSame( 6, (int) get_option( Migrator::OPTION ) );
		self::assertNotNull( get_role( Capabilities::ROLE_OPERATOR ) );
	}
}
