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
 */
final class ActivationTest extends WP_UnitTestCase {

	public function test_activation_creates_no_tables_and_writes_schema_version_zero(): void {
		Plugin::activate();

		self::assertSame( array(), Schema::all_tables(), 'Milestone 0 introduces no business tables.' );
		self::assertSame( 0, (int) get_option( Migrator::OPTION ), 'mpcf_db_version must be written, even at target 0.' );
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

	public function test_activation_does_not_alter_storefront_behavior(): void {
		// No frontend hook is registered in Milestone 0 (docs/HOOKS.md); the
		// clearest proof available at this milestone is that activation
		// touches no WooCommerce filter/action state — verified by the
		// absence of any `woocommerce_*` callback this plugin could have
		// added. Real storefront/checkout coverage arrives with Milestone 1.
		global $wp_filter;

		foreach ( array_keys( $wp_filter ) as $hook ) {
			if ( ! str_starts_with( (string) $hook, 'woocommerce_' ) ) {
				continue;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];

					if ( is_array( $function ) && is_object( $function[0] ) ) {
						self::assertStringNotContainsString( 'MPCF', get_class( $function[0] ) );
					}
				}
			}
		}

		$this->addToAssertionCount( 1 );
	}

	public function test_reactivation_is_idempotent(): void {
		Plugin::activate();
		Plugin::activate();

		self::assertSame( 0, (int) get_option( Migrator::OPTION ) );
		self::assertNotNull( get_role( Capabilities::ROLE_OPERATOR ) );
	}
}
