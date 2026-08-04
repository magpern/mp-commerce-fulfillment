<?php
/**
 * Unit tests for Orders next-action presentation mapping.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application;

use MPCF\Application\OrdersNextAction;
use PHPUnit\Framework\TestCase;

/**
 * Pure mapping tests — no WooCommerce or database.
 */
final class OrdersNextActionTest extends TestCase {

	public function test_pending_payment_without_fulfillment_awaits_payment(): void {
		$result = OrdersNextAction::describe( 'pending', null );

		self::assertSame( 'Awaiting payment', $result['operational_state'] );
		self::assertSame( 'Awaiting payment', $result['next_action'] );
		self::assertSame( OrdersNextAction::OPEN_WOOCOMMERCE, $result['open_target'] );
	}

	public function test_on_hold_without_fulfillment_awaits_confirmation(): void {
		$result = OrdersNextAction::describe( 'on-hold', null );

		self::assertSame( 'On hold', $result['operational_state'] );
		self::assertSame( 'Awaiting payment confirmation', $result['next_action'] );
		self::assertSame( OrdersNextAction::OPEN_WOOCOMMERCE, $result['open_target'] );
	}

	public function test_picking_fulfillment_continues_picking(): void {
		$result = OrdersNextAction::describe( 'processing', 'picking' );

		self::assertSame( 'Picking', $result['operational_state'] );
		self::assertSame( 'Continue picking', $result['next_action'] );
		self::assertSame( OrdersNextAction::OPEN_WORKSPACE, $result['open_target'] );
	}

	public function test_packed_fulfillment_is_ready_to_ship(): void {
		$result = OrdersNextAction::describe( 'processing', 'packed' );

		self::assertSame( 'Packed', $result['operational_state'] );
		self::assertSame( 'Ready to ship', $result['next_action'] );
		self::assertSame( OrdersNextAction::OPEN_WORKSPACE, $result['open_target'] );
	}

	public function test_shipped_fulfillment_reports_completed_next_action(): void {
		$result = OrdersNextAction::describe( 'completed', 'shipped' );

		self::assertSame( 'Shipped', $result['operational_state'] );
		self::assertSame( 'Completed', $result['next_action'] );
		self::assertSame( OrdersNextAction::OPEN_WORKSPACE, $result['open_target'] );
	}

	public function test_cancelled_order_reports_no_action(): void {
		$result = OrdersNextAction::describe( 'cancelled', null );

		self::assertSame( 'Cancelled', $result['operational_state'] );
		self::assertSame( 'No action', $result['next_action'] );
		self::assertSame( OrdersNextAction::OPEN_WOOCOMMERCE, $result['open_target'] );
	}

	public function test_never_creates_a_fulfillment_open_target_for_unpaid_orders(): void {
		foreach ( array( 'pending', 'on-hold', 'failed' ) as $status ) {
			$result = OrdersNextAction::describe( $status, null );
			self::assertSame( OrdersNextAction::OPEN_WOOCOMMERCE, $result['open_target'], $status );
			self::assertNotSame( OrdersNextAction::OPEN_WORKSPACE, $result['open_target'], $status );
		}
	}
}
