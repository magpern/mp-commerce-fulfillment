<?php
/**
 * Tests for ScanResolver resolution rules.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Scan;

use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Scan\ScanResolver;
use MPCF\Domain\Scan\ScanResolution;
use PHPUnit\Framework\TestCase;

/**
 * Part IX.3 deterministic resolution.
 */
final class ScanResolverTest extends TestCase {

	/**
	 * @return array<int, FulfillmentItem>
	 */
	private function items(): array {
		return array(
			FulfillmentItem::from_array(
				array(
					'id'             => 10,
					'fulfillment_id' => 5,
					'order_item_id'  => 1,
					'product_id'     => 100,
					'variation_id'   => 0,
					'sku_snapshot'   => 'SIMPLE-1',
					'name_snapshot'  => 'Simple',
					'qty_ordered'    => 2,
					'qty_picked'     => 0,
					'qty_packed'     => 0,
				)
			),
			FulfillmentItem::from_array(
				array(
					'id'             => 11,
					'fulfillment_id' => 5,
					'order_item_id'  => 2,
					'product_id'     => 200,
					'variation_id'   => 201,
					'sku_snapshot'   => 'VAR-RED',
					'name_snapshot'  => 'Var Red',
					'qty_ordered'    => 1,
					'qty_picked'     => 0,
					'qty_packed'     => 0,
				)
			),
			FulfillmentItem::from_array(
				array(
					'id'             => 12,
					'fulfillment_id' => 5,
					'order_item_id'  => 3,
					'product_id'     => 200,
					'variation_id'   => 202,
					'sku_snapshot'   => 'VAR-BLUE',
					'name_snapshot'  => 'Var Blue',
					'qty_ordered'    => 1,
					'qty_picked'     => 0,
					'qty_packed'     => 0,
				)
			),
		);
	}

	public function test_resolves_item_payload(): void {
		$result = ( new ScanResolver() )->resolve( 'MPCF:I:11', $this->items(), 5 );

		self::assertTrue( $result->is_item() );
		self::assertSame( 11, $result->item()->id() );
		self::assertSame( 'mpcf_payload', $result->source() );
	}

	public function test_resolves_exact_sku(): void {
		$result = ( new ScanResolver() )->resolve( 'VAR-RED', $this->items(), 5 );

		self::assertTrue( $result->is_item() );
		self::assertSame( 11, $result->item()->id() );
		self::assertSame( 'sku', $result->source() );
	}

	public function test_resolves_variation_payload(): void {
		$result = ( new ScanResolver() )->resolve( 'MPCF:V:202', $this->items(), 5 );

		self::assertTrue( $result->is_item() );
		self::assertSame( 12, $result->item()->id() );
	}

	public function test_rejects_unknown_sku(): void {
		$result = ( new ScanResolver() )->resolve( 'OTHER-ORDER-SKU', $this->items(), 5 );

		self::assertTrue( $result->is_rejected() );
		self::assertSame( 'unknown_barcode', $result->code() );
	}

	public function test_rejects_item_not_on_fulfillment(): void {
		$result = ( new ScanResolver() )->resolve( 'MPCF:I:999', $this->items(), 5 );

		self::assertSame( 'item_not_on_fulfillment', $result->code() );
	}

	public function test_rejects_wrong_fulfillment_identity(): void {
		$result = ( new ScanResolver() )->resolve( 'MPCF:F:99', $this->items(), 5 );

		self::assertSame( 'wrong_fulfillment', $result->code() );
	}

	public function test_accepts_matching_fulfillment_identity(): void {
		$result = ( new ScanResolver() )->resolve( 'MPCF:F:5', $this->items(), 5 );

		self::assertSame( ScanResolution::STATUS_FULFILLMENT, $result->status() );
		self::assertSame( 5, $result->identity_id() );
	}

	public function test_package_identity_does_not_require_item_match(): void {
		$result = ( new ScanResolver() )->resolve( 'MPCF:P:3', $this->items(), 5 );

		self::assertSame( ScanResolution::STATUS_PACKAGE, $result->status() );
		self::assertSame( 3, $result->identity_id() );
	}

	public function test_rejects_ambiguous_duplicate_sku(): void {
		$items   = $this->items();
		$items[] = FulfillmentItem::from_array(
			array(
				'id'             => 13,
				'fulfillment_id' => 5,
				'order_item_id'  => 4,
				'product_id'     => 300,
				'variation_id'   => 0,
				'sku_snapshot'   => 'SIMPLE-1',
				'name_snapshot'  => 'Dup',
				'qty_ordered'    => 1,
				'qty_picked'     => 0,
				'qty_packed'     => 0,
			)
		);

		$result = ( new ScanResolver() )->resolve( 'SIMPLE-1', $items, 5 );

		self::assertSame( 'ambiguous_sku', $result->code() );
	}

	public function test_rejects_parent_product_when_only_variations_match(): void {
		$result = ( new ScanResolver() )->resolve( 'MPCF:PR:200', $this->items(), 5 );

		self::assertSame( 'variation_required', $result->code() );
	}

	public function test_rejects_malformed_payload(): void {
		$result = ( new ScanResolver() )->resolve( 'MPCF:F:nope', $this->items(), 5 );

		self::assertSame( 'malformed_payload', $result->code() );
	}

	public function test_resolves_simple_product_payload(): void {
		$result = ( new ScanResolver() )->resolve( 'MPCF:PR:100', $this->items(), 5 );

		self::assertTrue( $result->is_item() );
		self::assertSame( 10, $result->item()->id() );
	}
}
