<?php
/**
 * Tests for the order snapshot value object.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain;

use MPCF\Domain\OrderLineSnapshot;
use MPCF\Domain\OrderSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the order snapshot value object.
 */
final class OrderSnapshotTest extends TestCase {

	public function test_create_exposes_every_field(): void {
		$line     = OrderLineSnapshot::create( 501, 900, 0, 'SKU-1', 'Widget', 3 );
		$snapshot = OrderSnapshot::create( 1001, 'woocommerce', '#1001', 'Jane Doe', 'processing', array( $line ) );

		self::assertSame( 1001, $snapshot->order_id() );
		self::assertSame( 'woocommerce', $snapshot->order_source() );
		self::assertSame( '#1001', $snapshot->order_number() );
		self::assertSame( 'Jane Doe', $snapshot->customer_name() );
		self::assertSame( 'processing', $snapshot->status() );
		self::assertSame( array( $line ), $snapshot->items() );
	}

	public function test_create_accepts_an_empty_item_list(): void {
		$snapshot = OrderSnapshot::create( 1001, 'woocommerce', '#1001', 'Jane Doe', 'processing', array() );

		self::assertSame( array(), $snapshot->items() );
	}

	public function test_ship_to_lines_defaults_to_empty(): void {
		$snapshot = OrderSnapshot::create( 1001, 'woocommerce', '#1001', 'Jane Doe', 'processing', array() );

		self::assertSame( array(), $snapshot->ship_to_lines() );
	}

	public function test_ship_to_lines_is_exposed_when_supplied(): void {
		$lines    = array( 'Anna Andersson', 'Storgatan 1', '111 22 Stockholm', 'SE' );
		$snapshot = OrderSnapshot::create( 1001, 'woocommerce', '#1001', 'Jane Doe', 'processing', array(), $lines );

		self::assertSame( $lines, $snapshot->ship_to_lines() );
	}
}
