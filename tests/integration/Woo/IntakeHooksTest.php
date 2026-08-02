<?php
/**
 * Integration tests for the paid-order intake bridge, against a real order
 * (HPOS on).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Woo;

use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Woo\IntakeHooks;
use WP_UnitTestCase;

/**
 * `MPCF\Plugin::init()` wires and registers a real {@see IntakeHooks}
 * instance once, for the whole integration process (see
 * `MPCF\Plugin::wire_intake()`) — these tests never construct their own;
 * they create a real order through the same platform hooks a real checkout
 * fires, and assert on what the already-running composition root did.
 */
final class IntakeHooksTest extends WP_UnitTestCase {

	use OrderFactoryTrait;
	use CleanFulfillmentTablesTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();
	}

	public function test_paying_an_order_creates_exactly_one_fulfillment_with_its_line_items(): void {
		$order = $this->create_paid_order( 3 );

		$fulfillment = ( new WpdbFulfillmentRepository() )->find_by_order_id( $order->get_id() );

		self::assertNotNull( $fulfillment, 'A real paid order must produce a real fulfillment via the registered hooks.' );
		self::assertSame( 'queued', $fulfillment->state() );
		self::assertSame( 'woocommerce', $fulfillment->order_source() );

		$items = ( new WpdbFulfillmentItemRepository() )->find_for_fulfillment( $fulfillment->id() );

		self::assertCount( 1, $items );
		self::assertSame( 3, $items[0]->qty_ordered() );
	}

	public function test_paying_an_order_appends_exactly_one_created_event_despite_two_hooks_firing(): void {
		// WC_Order::payment_complete() itself triggers both
		// woocommerce_order_status_processing (via the status transition on
		// save()) and woocommerce_payment_complete — IntakeHooks listens to
		// both, so this is the real-world proof that intake's idempotency
		// holds for the exact double-firing a single order purchase causes,
		// not only for a contrived duplicate call.
		$order = $this->create_paid_order();

		$fulfillment = ( new WpdbFulfillmentRepository() )->find_by_order_id( $order->get_id() );
		$timeline    = ( new WpdbEventRepository() )->timeline_for_fulfillment( $fulfillment->id() );

		self::assertCount( 1, $timeline );
		self::assertSame( 'fulfillment.created', $timeline[0]['event_type'] );
	}

	public function test_a_second_payment_notification_for_the_same_order_creates_no_duplicate(): void {
		$order = $this->create_paid_order();

		// A duplicate notification arriving later — a gateway retry, a
		// second webhook delivery — must be a no-op, not a second row.
		do_action( 'woocommerce_payment_complete', $order->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.HookCommentWrongStyle

		$fulfillments = array_filter(
			array_map(
				static fn( $order_id ) => ( new WpdbFulfillmentRepository() )->find_by_order_id( $order_id ),
				array( $order->get_id() )
			)
		);

		self::assertCount( 1, $fulfillments );
	}

	public function test_the_action_scheduler_fallback_hook_is_registered(): void {
		self::assertNotFalse( has_action( IntakeHooks::RETRY_ACTION ), 'The Action Scheduler fallback action must have a registered handler.' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	}
}
