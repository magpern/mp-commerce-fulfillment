<?php
/**
 * Tests for the order line snapshot value object.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain;

use MPCF\Domain\OrderLineSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the order line snapshot value object.
 */
final class OrderLineSnapshotTest extends TestCase {

	public function test_create_exposes_every_field(): void {
		$line = OrderLineSnapshot::create( 501, 900, 5, 'SKU-1', 'Widget', 3 );

		self::assertSame( 501, $line->order_item_id() );
		self::assertSame( 900, $line->product_id() );
		self::assertSame( 5, $line->variation_id() );
		self::assertSame( 'SKU-1', $line->sku() );
		self::assertSame( 'Widget', $line->name() );
		self::assertSame( 3, $line->quantity() );
	}
}
