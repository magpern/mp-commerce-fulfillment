<?php
/**
 * Tests for the package-dimensions value object.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Shipping;

use MPCF\Domain\Shipping\PackageSpec;
use PHPUnit\Framework\TestCase;

/**
 * Tests for this class.
 */
final class PackageSpecTest extends TestCase {

	public function test_none_is_not_present(): void {
		$spec = PackageSpec::none();

		self::assertFalse( $spec->is_present() );
		self::assertNull( $spec->weight_grams() );
		self::assertNull( $spec->length_mm() );
	}

	public function test_is_present_once_a_weight_is_recorded_even_without_dimensions(): void {
		$spec = PackageSpec::create( 1200 );

		self::assertTrue( $spec->is_present() );
		self::assertSame( 1200, $spec->weight_grams() );
		self::assertNull( $spec->length_mm() );
	}

	public function test_create_carries_every_dimension(): void {
		$spec = PackageSpec::create( 1200, 300, 200, 100 );

		self::assertSame( 1200, $spec->weight_grams() );
		self::assertSame( 300, $spec->length_mm() );
		self::assertSame( 200, $spec->width_mm() );
		self::assertSame( 100, $spec->height_mm() );
	}

	public function test_negative_values_clamp_to_zero(): void {
		$spec = PackageSpec::create( -5, -1, -1, -1 );

		self::assertSame( 0, $spec->weight_grams() );
		self::assertSame( 0, $spec->length_mm() );
		self::assertTrue( $spec->is_present() );
	}
}
