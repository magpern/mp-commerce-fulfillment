<?php
/**
 * Integration tests for the inbound WooCommerce cancel/refund/item-change
 * observer.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Woo;

use MPCF\Application\EventDispatcher;
use MPCF\Application\WorkflowService;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Engine\GuardRegistry;
use MPCF\Engine\WorkflowEngine;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\SystemClock;
use MPCF\Settings;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Woo\BridgeReentrancyGuard;
use MPCF\Woo\RefundObserver;
use MPCF\Woo\WooOrderSource;
use WP_UnitTestCase;

/**
 * Every RefundObserver reaction is exercised via the real hook it listens
 * for, against a real order — proving the observer, not merely the private
 * decision logic it delegates to `WorkflowService` for.
 */
final class RefundObserverTest extends WP_UnitTestCase {

	use OrderFactoryTrait;
	use CleanFulfillmentTablesTrait;

	/**
	 * @var WpdbFulfillmentRepository
	 */
	private WpdbFulfillmentRepository $fulfillments;

	/**
	 * @var WpdbFulfillmentItemRepository
	 */
	private WpdbFulfillmentItemRepository $items;

	/**
	 * @var WpdbEventRepository
	 */
	private WpdbEventRepository $events;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();

		$this->fulfillments = new WpdbFulfillmentRepository();
		$this->items        = new WpdbFulfillmentItemRepository();
		$this->events       = new WpdbEventRepository();
	}

	/**
	 * Registers a `RefundObserver` built with the given settings against the
	 * real hooks WordPress dispatches — mirrors `Plugin::wire_services()`.
	 * The plugin's own globally-wired instance (constructed once, with
	 * default settings, at bootstrap) is removed first: otherwise every
	 * hook fired below would reach *both* that instance and this test's
	 * differently-configured one, double-reacting to a single real event.
	 * WP_UnitTestCase's per-test hook backup/restore means this never
	 * leaks into another test.
	 */
	private function register_observer( Settings $settings ): void {
		remove_all_actions( 'woocommerce_order_status_cancelled' );
		remove_all_actions( 'woocommerce_order_fully_refunded' );
		remove_all_actions( 'woocommerce_order_partially_refunded' );
		remove_all_actions( 'woocommerce_saved_order_items' );

		$service = new WorkflowService(
			$this->fulfillments,
			$this->items,
			$this->events,
			new WorkflowEngine( GuardRegistry::standard() ),
			new EventDispatcher(),
			new SystemClock(),
			array( StandardWorkflow::NAME => StandardWorkflow::definition() )
		);

		( new RefundObserver( $this->fulfillments, $this->items, new WooOrderSource(), $service, $settings ) )->register();
	}

	/**
	 * Advances a real, already-ingested fulfillment to `picking` — a
	 * working state, so the exception-entry ("flag") edge actually exists,
	 * unlike the default `queued` state.
	 */
	private function advance_to_picking( int $fulfillment_id ): void {
		$service = new WorkflowService(
			$this->fulfillments,
			$this->items,
			$this->events,
			new WorkflowEngine( GuardRegistry::standard() ),
			new EventDispatcher(),
			new SystemClock(),
			array( StandardWorkflow::NAME => StandardWorkflow::definition() )
		);

		$service->transition( $fulfillment_id, 'picking', Actor::system() );
	}

	public function test_cancellation_moves_a_queued_fulfillment_to_cancelled_by_default(): void {
		$this->register_observer( new Settings() );

		$order       = $this->create_paid_order();
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );

		$order->update_status( 'cancelled' );

		$reloaded = $this->fulfillments->find( $fulfillment->id() );
		self::assertSame( 'cancelled', $reloaded->state() );
	}

	public function test_cancellation_flags_problem_when_the_fulfillment_is_already_being_worked_and_behavior_is_flag(): void {
		$this->register_observer( new Settings( array( 'inbound_cancel_behavior' => Settings::BRIDGE_BEHAVIOR_FLAG ) ) );

		$order       = $this->create_paid_order();
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );
		$this->advance_to_picking( $fulfillment->id() );

		$order->update_status( 'cancelled' );

		$reloaded = $this->fulfillments->find( $fulfillment->id() );
		self::assertSame( 'problem', $reloaded->state() );
	}

	public function test_cancellation_with_flag_behavior_falls_back_to_cancelled_when_the_fulfillment_is_still_queued(): void {
		$this->register_observer( new Settings( array( 'inbound_cancel_behavior' => Settings::BRIDGE_BEHAVIOR_FLAG ) ) );

		$order       = $this->create_paid_order();
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );

		// Still `queued` — no exception-entry edge exists from it, so
		// "flag" has nothing to fall back to except cancelling outright.
		$order->update_status( 'cancelled' );

		$reloaded = $this->fulfillments->find( $fulfillment->id() );
		self::assertSame( 'cancelled', $reloaded->state() );
	}

	public function test_a_full_refund_flags_problem_by_default(): void {
		$this->register_observer( new Settings() );

		$order       = $this->create_paid_order();
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );
		$this->advance_to_picking( $fulfillment->id() );

		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => $order->get_total(),
				'reason'   => 'test full refund',
			)
		);

		$reloaded = $this->fulfillments->find( $fulfillment->id() );
		self::assertSame( 'problem', $reloaded->state() );
	}

	public function test_a_full_refund_cancels_when_behavior_is_set_to_cancel(): void {
		$this->register_observer( new Settings( array( 'inbound_refund_behavior' => Settings::BRIDGE_BEHAVIOR_CANCEL ) ) );

		$order       = $this->create_paid_order();
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );

		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => $order->get_total(),
				'reason'   => 'test full refund',
			)
		);

		$reloaded = $this->fulfillments->find( $fulfillment->id() );
		self::assertSame( 'cancelled', $reloaded->state() );
	}

	public function test_a_partial_refund_always_flags_problem_regardless_of_the_refund_behavior_setting(): void {
		// Even with the refund behavior set to "cancel", a partial refund
		// must never auto-cancel — R3's generic mitigation applies (a
		// partial refund is a post-intake money-lifecycle change with no
		// automatic-cancel setting of its own).
		$this->register_observer( new Settings( array( 'inbound_refund_behavior' => Settings::BRIDGE_BEHAVIOR_CANCEL ) ) );

		$order       = $this->create_paid_order( 4 );
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );
		$this->advance_to_picking( $fulfillment->id() );

		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 1.00,
				'reason'   => 'test partial refund',
			)
		);

		$reloaded = $this->fulfillments->find( $fulfillment->id() );
		self::assertSame( 'problem', $reloaded->state() );
	}

	public function test_item_addition_after_intake_flags_problem_with_a_diff_summary(): void {
		$this->register_observer( new Settings() );

		$order       = $this->create_paid_order();
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );
		$this->advance_to_picking( $fulfillment->id() );

		$order->add_product( $this->create_product( 'Second Widget' ), 1 );
		$order->calculate_totals();
		$order->save();

		do_action( 'woocommerce_saved_order_items', $order->get_id(), array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.HookCommentWrongStyle

		$reloaded = $this->fulfillments->find( $fulfillment->id() );
		self::assertSame( 'problem', $reloaded->state() );

		$timeline = $this->events->timeline_for_fulfillment( $fulfillment->id() );
		$last     = end( $timeline );
		self::assertStringContainsString( 'added order_item_id', $last['payload']['reason'] );
	}

	public function test_item_removal_after_intake_flags_problem_with_a_diff_summary(): void {
		$this->register_observer( new Settings() );

		$order       = $this->create_paid_order( 1 );
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );
		$this->advance_to_picking( $fulfillment->id() );

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$order->remove_item( $item_id );
		}
		$order->calculate_totals();
		$order->save();

		do_action( 'woocommerce_saved_order_items', $order->get_id(), array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.HookCommentWrongStyle

		$reloaded = $this->fulfillments->find( $fulfillment->id() );
		self::assertSame( 'problem', $reloaded->state() );

		$timeline = $this->events->timeline_for_fulfillment( $fulfillment->id() );
		$last     = end( $timeline );
		self::assertStringContainsString( 'removed order_item_id', $last['payload']['reason'] );
	}

	public function test_item_quantity_change_after_intake_flags_problem_with_a_diff_summary(): void {
		$this->register_observer( new Settings() );

		$order       = $this->create_paid_order( 1 );
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );
		$this->advance_to_picking( $fulfillment->id() );

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$item->set_quantity( 5 );
			$item->save();
		}
		$order->calculate_totals();
		$order->save();

		do_action( 'woocommerce_saved_order_items', $order->get_id(), array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.HookCommentWrongStyle

		$reloaded = $this->fulfillments->find( $fulfillment->id() );
		self::assertSame( 'problem', $reloaded->state() );

		$timeline = $this->events->timeline_for_fulfillment( $fulfillment->id() );
		$last     = end( $timeline );
		self::assertStringContainsString( 'quantity', $last['payload']['reason'] );
	}

	public function test_an_unchanged_item_save_creates_no_transition_or_event(): void {
		$this->register_observer( new Settings() );

		$order       = $this->create_paid_order();
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );

		$before = count( $this->events->timeline_for_fulfillment( $fulfillment->id() ) );

		do_action( 'woocommerce_saved_order_items', $order->get_id(), array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.HookCommentWrongStyle

		$reloaded = $this->fulfillments->find( $fulfillment->id() );
		self::assertSame( 'queued', $reloaded->state(), 'No real change means no transition.' );
		self::assertCount( $before, $this->events->timeline_for_fulfillment( $fulfillment->id() ), 'No real change means no new audit event.' );
	}

	public function test_a_repeated_identical_item_change_does_not_create_a_second_event(): void {
		$this->register_observer( new Settings() );

		$order       = $this->create_paid_order();
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );
		$this->advance_to_picking( $fulfillment->id() );

		$order->add_product( $this->create_product( 'Second Widget' ), 1 );
		$order->calculate_totals();
		$order->save();

		do_action( 'woocommerce_saved_order_items', $order->get_id(), array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.HookCommentWrongStyle
		$after_first = count( $this->events->timeline_for_fulfillment( $fulfillment->id() ) );

		// The same admin save firing again (e.g. a duplicated AJAX request)
		// against the identical, still-diffed live order — the fulfillment
		// is already `problem`, which is not a working state, so no
		// exception-entry edge exists to take a second time.
		do_action( 'woocommerce_saved_order_items', $order->get_id(), array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.HookCommentWrongStyle

		self::assertCount( $after_first, $this->events->timeline_for_fulfillment( $fulfillment->id() ), 'A repeated identical diff must not append a second event.' );
	}

	public function test_the_diff_summary_payload_contains_no_customer_data(): void {
		$this->register_observer( new Settings() );

		$order       = $this->create_paid_order();
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );
		$this->advance_to_picking( $fulfillment->id() );

		$order->add_product( $this->create_product( 'Second Widget' ), 1 );
		$order->calculate_totals();
		$order->save();

		do_action( 'woocommerce_saved_order_items', $order->get_id(), array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.HookCommentWrongStyle

		$timeline = $this->events->timeline_for_fulfillment( $fulfillment->id() );
		$last     = end( $timeline );
		$payload  = wp_json_encode( $last['payload'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_wp_json_encode -- test assertion only, not a persisted write.

		self::assertStringNotContainsString( 'Jane', $payload, 'The diff summary must never carry the customer name.' );
		self::assertStringNotContainsString( '@', $payload, 'The diff summary must never carry anything email-shaped.' );
	}

	public function test_the_bridge_reentrancy_guard_prevents_the_observer_from_reacting(): void {
		$this->register_observer( new Settings() );

		$order       = $this->create_paid_order();
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );

		BridgeReentrancyGuard::run(
			function () use ( $order ): void {
				$order->update_status( 'cancelled' );
			}
		);

		$reloaded = $this->fulfillments->find( $fulfillment->id() );
		self::assertSame( 'queued', $reloaded->state(), 'While the re-entrancy guard is held, the inbound observer must not react.' );
	}
}
