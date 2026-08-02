<?php
/**
 * Tests for the fulfillment line item entity.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain;

use MPCF\Domain\FulfillmentItem;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the fulfillment line item entity.
 */
final class FulfillmentItemTest extends TestCase {

	public function test_intake_builds_an_item_with_nothing_picked_or_packed(): void {
		$item = FulfillmentItem::intake( 1, 501, 900, 0, 'SKU-1', 'Widget', 4 );

		self::assertNull( $item->id() );
		self::assertSame( 1, $item->fulfillment_id() );
		self::assertSame( 501, $item->order_item_id() );
		self::assertSame( 900, $item->product_id() );
		self::assertSame( 0, $item->variation_id() );
		self::assertSame( 'SKU-1', $item->sku_snapshot() );
		self::assertSame( 'Widget', $item->name_snapshot() );
		self::assertSame( 4, $item->qty_ordered() );
		self::assertSame( 0, $item->qty_picked() );
		self::assertSame( 0, $item->qty_packed() );
		self::assertNull( $item->location_snapshot() );
		self::assertFalse( $item->is_fully_picked() );
		self::assertFalse( $item->is_fully_packed() );
	}

	public function test_to_array_and_from_array_round_trip(): void {
		$item    = FulfillmentItem::intake( 1, 501, 900, 0, 'SKU-1', 'Widget', 4 );
		$rebuilt = FulfillmentItem::from_array( $item->to_array() );

		self::assertSame( $item->to_array(), $rebuilt->to_array() );
	}

	public function test_record_picked_and_record_packed_track_fully_state(): void {
		$item = FulfillmentItem::intake( 1, 501, 900, 0, 'SKU-1', 'Widget', 4 );

		$item->record_picked( 2 );
		self::assertSame( 2, $item->qty_picked() );
		self::assertFalse( $item->is_fully_picked() );

		$item->record_picked( 4 );
		self::assertTrue( $item->is_fully_picked() );

		$item->record_packed( 4 );
		self::assertTrue( $item->is_fully_packed() );
	}

	public function test_record_picked_and_record_packed_never_go_negative(): void {
		$item = FulfillmentItem::intake( 1, 501, 900, 0, 'SKU-1', 'Widget', 4 );

		$item->record_picked( -3 );
		$item->record_packed( -1 );

		self::assertSame( 0, $item->qty_picked() );
		self::assertSame( 0, $item->qty_packed() );
	}

	public function test_record_picked_and_record_packed_never_exceed_qty_ordered(): void {
		$item = FulfillmentItem::intake( 1, 501, 900, 0, 'SKU-1', 'Widget', 4 );

		$item->record_picked( 999 );
		$item->record_packed( 999 );

		self::assertSame( 4, $item->qty_picked() );
		self::assertSame( 4, $item->qty_packed() );
	}
}
