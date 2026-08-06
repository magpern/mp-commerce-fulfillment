<?php
/**
 * Tests for the packing slip's document assembler.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Engine\DocumentAssembler;

use DateTimeImmutable;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\OrderSnapshot;
use MPCF\Domain\Shipping\Package;
use MPCF\Domain\Shipping\PackageSpec;
use MPCF\Engine\DocumentAssembler\PackingSlipAssembler;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the packing slip's document assembler.
 */
final class PackingSlipAssemblerTest extends TestCase {

	private function fulfillment(): Fulfillment {
		$data       = Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'packing', '#1001', 'Jane Doe', 1, new DateTimeImmutable() )->to_array();
		$data['id'] = 42;

		return Fulfillment::from_array( $data );
	}

	public function test_order_number_and_customer_name_come_from_the_fulfillments_own_snapshot_not_the_order(): void {
		$order = OrderSnapshot::create( 1001, 'woocommerce', '#1001-STALE', 'Someone Else', 'processing', array() );

		$model = PackingSlipAssembler::assemble( $this->fulfillment(), $order, array(), array(), 'Acme Store' );

		self::assertSame( '#1001', $model->order_number(), 'order_number must come from the fulfillment snapshot, never the live order read.' );
		self::assertSame( 'Jane Doe', $model->customer_name() );
	}

	public function test_ship_to_lines_come_from_the_order_snapshot(): void {
		$lines = array( 'Anna Andersson', 'Storgatan 1', '111 22 Stockholm', 'SE' );
		$order = OrderSnapshot::create( 1001, 'woocommerce', '#1001', 'Jane Doe', 'processing', array(), $lines );

		$model = PackingSlipAssembler::assemble( $this->fulfillment(), $order, array(), array(), 'Acme Store' );

		self::assertSame( $lines, $model->ship_to_lines() );
	}

	public function test_items_are_built_from_fulfillment_item_snapshots(): void {
		$order = OrderSnapshot::create( 1001, 'woocommerce', '#1001', 'Jane Doe', 'processing', array() );
		$item  = FulfillmentItem::intake( 1, 501, 900, 0, 'SKU-1', 'Blue Widget', 3 );

		$model = PackingSlipAssembler::assemble( $this->fulfillment(), $order, array( $item ), array(), 'Acme Store' );

		self::assertSame(
			array( array( 'sku' => 'SKU-1', 'name' => 'Blue Widget', 'qty_ordered' => 3, 'qty_packed' => 0 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$model->items()
		);
	}

	public function test_packages_are_summarized_by_seq_and_spec(): void {
		$order   = OrderSnapshot::create( 1001, 'woocommerce', '#1001', 'Jane Doe', 'processing', array() );
		$package = Package::create( 1, 1, new DateTimeImmutable() );
		$package->set_spec( PackageSpec::create( 1200, 300, 200, 100 ) );
		$package->set_tracking_number( 'COLLI-1' );

		$model = PackingSlipAssembler::assemble( $this->fulfillment(), $order, array(), array( $package ), 'Acme Store' );

		self::assertSame(
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
			$model->packages()
		);
	}

	public function test_barcode_payload_is_namespaced_fulfillment_id(): void {
		$order = OrderSnapshot::create( 1001, 'woocommerce', '#1001', 'Jane Doe', 'processing', array() );

		$model = PackingSlipAssembler::assemble( $this->fulfillment(), $order, array(), array(), 'Acme Store' );

		self::assertSame( 'MPCF:F:42', $model->barcode_payload() );
		self::assertSame( '#1001', $model->order_number() );
	}

	public function test_doc_type_and_store_name_and_fulfillment_id(): void {
		$order = OrderSnapshot::create( 1001, 'woocommerce', '#1001', 'Jane Doe', 'processing', array() );

		$model = PackingSlipAssembler::assemble( $this->fulfillment(), $order, array(), array(), 'Acme Store' );

		self::assertSame( PackingSlipAssembler::DOC_TYPE, $model->doc_type() );
		self::assertSame( 'Acme Store', $model->store_name() );
	}
}
