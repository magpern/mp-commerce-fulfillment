<?php
/**
 * WaveWalkBuilder unit tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Wave;

use DateTimeImmutable;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Wave\WaveWalkBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Walk grouping / sort / FIFO allocations.
 */
final class WaveWalkBuilderTest extends TestCase {

	public function test_groups_duplicate_sku_and_sorts_null_location_last(): void {
		$now = new DateTimeImmutable( '2026-08-06 10:00:00' );
		$f1  = $this->fulfillment( 1, '2026-08-06 09:00:00' );
		$f2  = $this->fulfillment( 2, '2026-08-06 09:30:00' );

		$i1 = $this->item( 11, 1, 'SKU-A', 'Aisle 1', 2, 0 );
		$i2 = $this->item( 12, 2, 'SKU-A', null, 1, 0 );
		$i3 = $this->item( 13, 2, 'SKU-B', 'Aisle 2', 1, 0 );

		$walk = ( new WaveWalkBuilder() )->build(
			array(
				1 => $f1,
				2 => $f2,
			),
			array(
				1 => array( $i1 ),
				2 => array( $i2, $i3 ),
			)
		);

		self::assertCount( 3, $walk['rows'] );
		self::assertSame( 'Aisle 1', $walk['rows'][0]['location_snapshot'] );
		self::assertSame( 'Aisle 2', $walk['rows'][1]['location_snapshot'] );
		self::assertNull( $walk['rows'][2]['location_snapshot'] );
		self::assertSame( 'SKU-A', $walk['rows'][0]['sku_snapshot'] );
		self::assertSame( 2, $walk['rows'][0]['required_qty'] );
		self::assertSame( 1, $walk['rows'][2]['required_qty'] );
	}

	public function test_variations_do_not_collapse(): void {
		$f1 = $this->fulfillment( 1, '2026-08-06 09:00:00' );
		$a  = $this->item( 1, 1, 'TEE', 'A', 1, 0, 10, 100 );
		$b  = $this->item( 2, 1, 'TEE', 'A', 1, 0, 10, 200 );

		$walk = ( new WaveWalkBuilder() )->build(
			array( 1 => $f1 ),
			array( 1 => array( $a, $b ) )
		);

		self::assertCount( 2, $walk['rows'] );
	}

	public function test_fifo_allocation_order(): void {
		$f1 = $this->fulfillment( 1, '2026-08-06 09:00:00' );
		$f2 = $this->fulfillment( 2, '2026-08-06 08:00:00' );
		$i1 = $this->item( 50, 1, 'SKU', 'L', 1, 0 );
		$i2 = $this->item( 40, 2, 'SKU', 'L', 1, 0 );

		$walk = ( new WaveWalkBuilder() )->build(
			array(
				1 => $f1,
				2 => $f2,
			),
			array(
				1 => array( $i1 ),
				2 => array( $i2 ),
			)
		);

		self::assertCount( 1, $walk['rows'] );
		self::assertSame( 2, $walk['rows'][0]['required_qty'] );
		self::assertSame( 2, $walk['rows'][0]['allocations'][0]['fulfillment_id'] );
		self::assertSame( 1, $walk['rows'][0]['allocations'][1]['fulfillment_id'] );
	}

	private function fulfillment( int $id, string $created ): Fulfillment {
		return Fulfillment::from_array(
			array(
				'id'                     => $id,
				'order_id'               => $id * 10,
				'order_source'           => 'woocommerce',
				'warehouse_id'           => 1,
				'workflow'               => 'standard',
				'state'                  => 'picking',
				'previous_state'         => null,
				'return_to_state'        => null,
				'exception_reason'       => null,
				'priority'               => 0,
				'assignee_type'          => null,
				'assignee_id'            => null,
				'version'                => 1,
				'order_number_snapshot'  => (string) $id,
				'customer_name_snapshot' => 'Test',
				'item_count'             => 1,
				'created_at'             => new DateTimeImmutable( $created ),
				'state_entered_at'       => new DateTimeImmutable( $created ),
				'completed_at'           => null,
			)
		);
	}

	private function item(
		int $id,
		int $fid,
		string $sku,
		?string $location,
		int $ordered,
		int $picked,
		int $product_id = 1,
		int $variation_id = 0
	): FulfillmentItem {
		return FulfillmentItem::from_array(
			array(
				'id'                => $id,
				'fulfillment_id'    => $fid,
				'order_item_id'     => $id,
				'product_id'        => $product_id,
				'variation_id'      => $variation_id,
				'sku_snapshot'      => $sku,
				'name_snapshot'     => $sku,
				'qty_ordered'       => $ordered,
				'qty_picked'        => $picked,
				'qty_packed'        => 0,
				'location_snapshot' => $location,
			)
		);
	}
}
