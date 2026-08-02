<?php
/**
 * Tests for the assembled document model.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Document;

use MPCF\Domain\Document\DocumentModel;
use PHPUnit\Framework\TestCase;

/**
 * Tests for this class.
 */
final class DocumentModelTest extends TestCase {

	public function test_model_carries_every_assembled_field(): void {
		$model = new DocumentModel(
			'packing_slip',
			42,
			'#1042',
			'Anna Andersson',
			array( 'Storgatan 1', '111 22 Stockholm', 'SE' ),
			'Acme Store',
			array(
				array(
					'sku'         => 'SKU-1',
					'name'        => 'Blue Widget',
					'qty_ordered' => 2,
				),
			),
			array(
				array(
					'seq'             => 1,
					'weight_grams'    => 1200,
					'length_mm'       => 300,
					'width_mm'        => 200,
					'height_mm'       => 100,
					'tracking_number' => 'COLLI-1',
				),
			),
			'1042'
		);

		self::assertSame( 'packing_slip', $model->doc_type() );
		self::assertSame( 42, $model->fulfillment_id() );
		self::assertSame( '#1042', $model->order_number() );
		self::assertSame( 'Anna Andersson', $model->customer_name() );
		self::assertSame( array( 'Storgatan 1', '111 22 Stockholm', 'SE' ), $model->ship_to_lines() );
		self::assertSame( 'Acme Store', $model->store_name() );
		self::assertCount( 1, $model->items() );
		self::assertSame( 'SKU-1', $model->items()[0]['sku'] );
		self::assertCount( 1, $model->packages() );
		self::assertSame( 1200, $model->packages()[0]['weight_grams'] );
		self::assertSame( '1042', $model->barcode_payload() );
	}
}
