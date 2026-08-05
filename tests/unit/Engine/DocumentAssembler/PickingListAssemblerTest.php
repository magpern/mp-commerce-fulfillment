<?php
/**
 * Picking list assembler tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Engine\DocumentAssembler;

use DateTimeImmutable;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\OrderSnapshot;
use MPCF\Engine\DocumentAssembler\PickingListAssembler;
use PHPUnit\Framework\TestCase;

/**
 * Picking-list model population and ordering.
 */
final class PickingListAssemblerTest extends TestCase {

	public function test_items_preserve_fulfillment_line_order(): void {
		$fulfillment = Fulfillment::intake( 10, 'woocommerce', 1, 'standard', 'picking', '#10', 'A', 1, new DateTimeImmutable() );
		$order       = OrderSnapshot::create( 10, 'woocommerce', '#10', 'A', 'processing', array(), array(), 'Handle with care' );

		$first  = FulfillmentItem::from_array(
			array(
				'fulfillment_id'    => 1,
				'order_item_id'     => 1,
				'product_id'        => 1,
				'variation_id'      => 0,
				'sku_snapshot'      => 'SKU-A',
				'name_snapshot'     => 'Alpha',
				'qty_ordered'       => 3,
				'qty_picked'        => 1,
				'qty_packed'        => 0,
				'location_snapshot' => 'B-2',
			)
		);
		$second = FulfillmentItem::from_array(
			array(
				'fulfillment_id'    => 1,
				'order_item_id'     => 2,
				'product_id'        => 2,
				'variation_id'      => 0,
				'sku_snapshot'      => 'SKU-B',
				'name_snapshot'     => 'Beta',
				'qty_ordered'       => 2,
				'qty_picked'        => 0,
				'qty_packed'        => 0,
				'location_snapshot' => 'A-1',
			)
		);

		$model = PickingListAssembler::assemble( $fulfillment, $order, array( $first, $second ), 'Store', array( 'store_name' => 'Store' ) );

		self::assertSame( 'SKU-A', $model->items()[0]['sku'] );
		self::assertSame( 'SKU-B', $model->items()[1]['sku'] );
		self::assertSame( 3, $model->items()[0]['qty_to_pick'] );
		self::assertSame( 1, $model->items()[0]['qty_picked'] );
		self::assertSame( 2, $model->items()[0]['qty_remaining'] );
		self::assertSame( 'B-2', $model->items()[0]['location_snapshot'] );
		self::assertSame( 'Handle with care', $model->customer_instructions() );
		self::assertSame( PickingListAssembler::DOC_TYPE, $model->doc_type() );
		self::assertSame( array(), $model->packages() );
	}
}
