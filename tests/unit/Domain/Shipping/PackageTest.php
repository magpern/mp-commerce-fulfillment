<?php
/**
 * Tests for the package entity.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Shipping;

use DateTimeImmutable;
use MPCF\Domain\Shipping\Package;
use MPCF\Domain\Shipping\PackageSpec;
use PHPUnit\Framework\TestCase;

/**
 * Tests for this class.
 */
final class PackageTest extends TestCase {

	public function test_create_builds_a_package_with_no_spec_recorded(): void {
		$now     = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$package = Package::create( 5, 1, $now );

		self::assertNull( $package->id() );
		self::assertSame( 5, $package->shipment_id() );
		self::assertSame( 1, $package->seq() );
		self::assertFalse( $package->spec()->is_present() );
		self::assertNull( $package->tracking_number() );
		self::assertNull( $package->label_path() );
		self::assertSame( $now, $package->created_at() );
	}

	public function test_set_spec_and_set_tracking_number(): void {
		$package = Package::create( 5, 1, new DateTimeImmutable() );

		$package->set_spec( PackageSpec::create( 1200, 300, 200, 100 ) );
		$package->set_tracking_number( 'COLLI-1' );

		self::assertTrue( $package->spec()->is_present() );
		self::assertSame( 1200, $package->spec()->weight_grams() );
		self::assertSame( 'COLLI-1', $package->tracking_number() );
	}

	public function test_set_tracking_number_with_empty_string_clears_it(): void {
		$package = Package::create( 5, 1, new DateTimeImmutable() );
		$package->set_tracking_number( 'COLLI-1' );

		$package->set_tracking_number( '' );

		self::assertNull( $package->tracking_number() );
	}

	public function test_to_array_and_from_array_round_trip(): void {
		$now     = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$package = Package::create( 5, 2, $now );
		$package->set_spec( PackageSpec::create( 1200, 300, 200, 100 ) );
		$package->set_tracking_number( 'COLLI-1' );

		$rebuilt = Package::from_array( array( 'id' => 9 ) + $package->to_array() );

		self::assertSame( 9, $rebuilt->id() );
		self::assertSame( 5, $rebuilt->shipment_id() );
		self::assertSame( 2, $rebuilt->seq() );
		self::assertSame( 1200, $rebuilt->spec()->weight_grams() );
		self::assertSame( 300, $rebuilt->spec()->length_mm() );
		self::assertSame( 'COLLI-1', $rebuilt->tracking_number() );
	}
}
