<?php
/**
 * Integration tests for the WooCommerce-backed order source, against a
 * real order (HPOS on).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Woo;

use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Woo\WooOrderSource;
use WP_UnitTestCase;

/**
 * Integration tests for the WooCommerce-backed order source, against a
 * real order (HPOS on).
 *
 * Since Milestone 1's `Woo\IntakeHooks` registers real
 * `woocommerce_payment_complete`/`woocommerce_order_status_processing`
 * listeners from the moment `Plugin::init()` runs (once, for the whole
 * integration process), every `create_paid_order()` call in this class now
 * also triggers a real (and, for this class's purposes, incidental) intake
 * — exactly as it would in production. {@see CleanFulfillmentTablesTrait}
 * ensures the fulfillment schema exists before that happens, so intake
 * succeeds silently instead of erroring against a missing table.
 */
final class WooOrderSourceTest extends WP_UnitTestCase {

	use OrderFactoryTrait;
	use CleanFulfillmentTablesTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();
	}

	public function test_find_returns_a_snapshot_matching_the_real_order(): void {
		$order = $this->create_paid_order( 3 );

		$snapshot = ( new WooOrderSource() )->find( $order->get_id() );

		self::assertNotNull( $snapshot );
		self::assertSame( $order->get_id(), $snapshot->order_id() );
		self::assertSame( 'woocommerce', $snapshot->order_source() );
		self::assertSame( $order->get_order_number(), $snapshot->order_number() );
		self::assertSame( 'Jane Doe', $snapshot->customer_name() );
		self::assertSame( 'processing', $snapshot->status() );
	}

	public function test_find_returns_the_orders_line_items(): void {
		$order = $this->create_paid_order( 3 );

		$snapshot = ( new WooOrderSource() )->find( $order->get_id() );

		self::assertCount( 1, $snapshot->items() );

		$line = $snapshot->items()[0];
		self::assertSame( 3, $line->quantity() );
		self::assertSame( 'Test Widget', $line->name() );
		self::assertNotSame( 0, $line->product_id() );
		self::assertSame( 0, $line->variation_id(), 'A simple product has no variation.' );
	}

	public function test_find_returns_null_for_an_order_id_that_does_not_exist(): void {
		self::assertNull( ( new WooOrderSource() )->find( 999999999 ) );
	}

	public function test_find_returns_the_orders_shipping_address_as_display_lines(): void {
		$order = $this->create_paid_order_with_shipping_address();

		$snapshot = ( new WooOrderSource() )->find( $order->get_id() );

		$lines = implode( '|', $snapshot->ship_to_lines() );

		self::assertStringContainsString( 'Anna Andersson', $lines );
		self::assertStringContainsString( 'Storgatan 1', $lines );
		self::assertStringContainsString( 'Stockholm', $lines );
	}

	public function test_find_falls_back_to_the_billing_address_when_no_shipping_address_is_set(): void {
		$order = $this->create_paid_order();

		$snapshot = ( new WooOrderSource() )->find( $order->get_id() );

		self::assertStringContainsString( 'Jane Doe', implode( '|', $snapshot->ship_to_lines() ) );
	}

	public function test_find_reads_correctly_regardless_of_which_hpos_storage_backend_is_active(): void {
		// The integration bootstrap forces HPOS on; this assertion exists
		// so a future run with HPOS off (the compatibility-matrix floor
		// leg) exercises the exact same assertions against the exact same
		// code path — there is no HPOS-specific branch in WooOrderSource
		// for this test to accidentally skip.
		$order    = $this->create_paid_order();
		$snapshot = ( new WooOrderSource() )->find( $order->get_id() );

		self::assertNotNull( $snapshot );
		self::assertCount( 1, $snapshot->items() );
	}

	public function test_find_returns_the_orders_customer_note(): void {
		$order = $this->create_paid_order();
		$order->set_customer_note( 'Pack in green bag' );
		$order->save();

		$snapshot = ( new WooOrderSource() )->find( $order->get_id() );

		self::assertNotNull( $snapshot );
		self::assertSame( 'Pack in green bag', $snapshot->customer_note() );
	}

	public function test_find_returns_an_empty_customer_note_when_none_is_set(): void {
		$order = $this->create_paid_order();

		$snapshot = ( new WooOrderSource() )->find( $order->get_id() );

		self::assertNotNull( $snapshot );
		self::assertSame( '', $snapshot->customer_note() );
	}
}
