<?php
/**
 * Integration tests for the store-unit conversion port.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Woo;

use MPCF\Woo\StoreUnits;
use WP_UnitTestCase;

/**
 * Integration tests for the store-unit conversion port.
 */
final class StoreUnitsTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		delete_option( 'woocommerce_weight_unit' );
		delete_option( 'woocommerce_dimension_unit' );

		parent::tearDown();
	}

	public function test_weight_unit_label_defaults_to_kg_when_unset(): void {
		delete_option( 'woocommerce_weight_unit' );

		self::assertSame( 'kg', ( new StoreUnits() )->weight_unit_label() );
	}

	public function test_weight_unit_label_reads_the_store_setting(): void {
		update_option( 'woocommerce_weight_unit', 'lbs' );

		self::assertSame( 'lbs', ( new StoreUnits() )->weight_unit_label() );
	}

	public function test_weight_unit_label_falls_back_to_kg_for_an_unrecognized_value(): void {
		update_option( 'woocommerce_weight_unit', 'stone' );

		self::assertSame( 'kg', ( new StoreUnits() )->weight_unit_label() );
	}

	public function test_dimension_unit_label_defaults_to_cm_when_unset(): void {
		delete_option( 'woocommerce_dimension_unit' );

		self::assertSame( 'cm', ( new StoreUnits() )->dimension_unit_label() );
	}

	public function test_grams_to_display_converts_using_the_store_unit(): void {
		update_option( 'woocommerce_weight_unit', 'kg' );

		self::assertSame( '1.2', ( new StoreUnits() )->grams_to_display( 1200 ) );
	}

	public function test_grams_to_display_trims_a_whole_number_cleanly(): void {
		update_option( 'woocommerce_weight_unit', 'kg' );

		self::assertSame( '2', ( new StoreUnits() )->grams_to_display( 2000 ) );
	}

	public function test_grams_to_display_is_empty_for_null(): void {
		self::assertSame( '', ( new StoreUnits() )->grams_to_display( null ) );
	}

	public function test_mm_to_display_converts_using_the_store_unit(): void {
		update_option( 'woocommerce_dimension_unit', 'cm' );

		self::assertSame( '30', ( new StoreUnits() )->mm_to_display( 300 ) );
	}

	public function test_mm_to_display_is_empty_for_null(): void {
		self::assertSame( '', ( new StoreUnits() )->mm_to_display( null ) );
	}

	public function test_grams_per_display_unit_matches_the_store_setting(): void {
		update_option( 'woocommerce_weight_unit', 'g' );

		self::assertSame( 1.0, ( new StoreUnits() )->grams_per_display_unit() );
	}

	public function test_mm_per_display_unit_matches_the_store_setting(): void {
		update_option( 'woocommerce_dimension_unit', 'in' );

		self::assertSame( 25.4, ( new StoreUnits() )->mm_per_display_unit() );
	}
}
