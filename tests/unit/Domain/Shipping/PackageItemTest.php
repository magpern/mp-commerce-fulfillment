<?php
/**
 * Tests for the per-package line-quantity allocation.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Shipping;

use MPCF\Domain\Shipping\PackageItem;
use PHPUnit\Framework\TestCase;

/**
 * Tests for this class.
 */
final class PackageItemTest extends TestCase {

	public function test_create_builds_an_allocation(): void {
		$item = PackageItem::create( 5, 900, 3 );

		self::assertNull( $item->id() );
		self::assertSame( 5, $item->package_id() );
		self::assertSame( 900, $item->fulfillment_item_id() );
		self::assertSame( 3, $item->qty() );
	}

	public function test_negative_qty_clamps_to_zero(): void {
		$item = PackageItem::create( 5, 900, -2 );

		self::assertSame( 0, $item->qty() );
	}

	public function test_to_array_and_from_array_round_trip(): void {
		$item    = PackageItem::create( 5, 900, 3 );
		$rebuilt = PackageItem::from_array( array( 'id' => 11 ) + $item->to_array() );

		self::assertSame( 11, $rebuilt->id() );
		self::assertSame( 5, $rebuilt->package_id() );
		self::assertSame( 900, $rebuilt->fulfillment_item_id() );
		self::assertSame( 3, $rebuilt->qty() );
	}
}
