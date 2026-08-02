<?php
/**
 * Inbound half of the WooCommerce status bridge.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Woo;

use MPCF\Application\WorkflowService;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\OrderSource;
use MPCF\Domain\Repository\FulfillmentItemRepository;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Settings;
use Throwable;

/**
 * Architecture Plan §6.6 (inbound) and R3 (post-intake desync): WooCommerce
 * remains authoritative for the money lifecycle; this class only ever
 * *proposes* a warehouse-lifecycle reaction, and every one of those
 * proposals flows through {@see WorkflowService} — never a direct write to
 * a fulfillment row. Every proposal that the engine's own workflow topology
 * rejects (typically: the fulfillment is already terminal, or is still
 * `queued` and has no exception-entry edge) is treated as a normal, silent
 * no-op, not an error — invariant I10, and also what makes repeated
 * observation of an unchanged condition safe: once a fulfillment reaches
 * `problem`/`cancelled`, the same edge is no longer available, so a second
 * identical hook firing (or a second admin save with no new change) can
 * never append a second audit event.
 *
 * Three inbound signals, each mapped per the frozen architecture:
 *
 * - `woocommerce_order_status_cancelled` -> cancellation behavior setting.
 * - `woocommerce_order_fully_refunded` -> refund behavior setting.
 * - `woocommerce_order_partially_refunded` -> always flagged `problem`
 *   (R3's generic mitigation for a post-intake money-lifecycle change; the
 *   architecture defines a dedicated automatic/review setting only for a
 *   *full* refund, so a partial one always goes to the safer, human-review
 *   outcome rather than assuming a policy the frozen document never named).
 * - `woocommerce_saved_order_items` -> a real line-item diff against what
 *   was snapshotted at intake; any addition, removal or quantity change
 *   always flags `problem` with a minimal diff summary as the transition's
 *   `reason` (which {@see WorkflowService} folds into the audit payload) —
 *   ids and quantities only, never a customer field.
 */
final class RefundObserver {

	/**
	 * Reads the fulfillment for an order, and its items for the diff.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

	/**
	 * Reads the items snapshotted at intake, for the item-change diff.
	 *
	 * @var FulfillmentItemRepository
	 */
	private FulfillmentItemRepository $items;

	/**
	 * Reads the live order, for the item-change diff.
	 *
	 * @var OrderSource
	 */
	private OrderSource $orders;

	/**
	 * The sole writer every proposal flows through.
	 *
	 * @var WorkflowService
	 */
	private WorkflowService $workflow;

	/**
	 * The inbound cancel/refund behavior toggles.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Builds the observer.
	 *
	 * @param FulfillmentRepository     $fulfillments Reads the fulfillment for an order.
	 * @param FulfillmentItemRepository $items        Reads items snapshotted at intake.
	 * @param OrderSource               $orders       Reads the live order.
	 * @param WorkflowService           $workflow     The sole writer every proposal flows through.
	 * @param Settings                  $settings     The inbound cancel/refund behavior toggles.
	 */
	public function __construct(
		FulfillmentRepository $fulfillments,
		FulfillmentItemRepository $items,
		OrderSource $orders,
		WorkflowService $workflow,
		Settings $settings
	) {
		$this->fulfillments = $fulfillments;
		$this->items        = $items;
		$this->orders       = $orders;
		$this->workflow     = $workflow;
		$this->settings     = $settings;
	}

	/**
	 * Registers every hook this class owns.
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_order_cancelled' ) );
		add_action( 'woocommerce_order_fully_refunded', array( $this, 'handle_order_fully_refunded' ) );
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'handle_order_partially_refunded' ) );
		add_action( 'woocommerce_saved_order_items', array( $this, 'handle_order_items_saved' ), 10, 2 );
	}

	/**
	 * A WC order was cancelled.
	 *
	 * @param int $order_id Order that was cancelled.
	 */
	public function handle_order_cancelled( int $order_id ): void {
		$this->safely(
			function () use ( $order_id ): void {
				$this->propose_cancel_or_flag( $order_id, $this->settings->inbound_cancel_behavior(), 'The WooCommerce order was cancelled.' );
			}
		);
	}

	/**
	 * A WC order was refunded in full.
	 *
	 * @param int $order_id Order that was fully refunded.
	 */
	public function handle_order_fully_refunded( int $order_id ): void {
		$this->safely(
			function () use ( $order_id ): void {
				$this->propose_cancel_or_flag( $order_id, $this->settings->inbound_refund_behavior(), 'The WooCommerce order was fully refunded.' );
			}
		);
	}

	/**
	 * A WC order was refunded in part. Always flagged for review — see this
	 * class's docblock for why a partial refund has no automatic-cancel
	 * setting of its own.
	 *
	 * @param int $order_id Order that was partially refunded.
	 */
	public function handle_order_partially_refunded( int $order_id ): void {
		$this->safely(
			function () use ( $order_id ): void {
				$this->flag_problem( $order_id, 'The WooCommerce order was partially refunded.' );
			}
		);
	}

	/**
	 * The admin order-edit screen saved a line-item change.
	 *
	 * @param int                      $order_id Order whose items were saved.
	 * @param array<int|string, mixed> $items    Raw posted item data (unused; the live order is re-read through {@see OrderSource} instead).
	 */
	public function handle_order_items_saved( int $order_id, array $items ): void {
		unset( $items );

		$this->safely(
			function () use ( $order_id ): void {
				$this->flag_item_changes( $order_id );
			}
		);
	}

	/**
	 * Proposes cancelling or flagging a fulfillment, per `$behavior`.
	 * Falls back to cancelling outright when the flag-into-`problem` edge
	 * does not exist from the fulfillment's current state (e.g. it is
	 * still `queued`, which no exception state is ever entered from) —
	 * there is nothing yet to "review," so cancelling is the only
	 * meaningful terminal action left.
	 *
	 * @param int    $order_id Order the fulfillment belongs to.
	 * @param string $behavior One of {@see Settings::BRIDGE_BEHAVIOR_CANCEL}/{@see Settings::BRIDGE_BEHAVIOR_FLAG}.
	 * @param string $reason   Reason recorded on the transition.
	 */
	private function propose_cancel_or_flag( int $order_id, string $behavior, string $reason ): void {
		if ( BridgeReentrancyGuard::is_active() ) {
			return;
		}

		$fulfillment = $this->fulfillments->find_by_order_id( $order_id );

		if ( null === $fulfillment ) {
			return;
		}

		if ( Settings::BRIDGE_BEHAVIOR_CANCEL === $behavior ) {
			$this->workflow->transition( $fulfillment->id(), 'cancelled', Actor::system(), $reason );

			return;
		}

		$outcome = $this->workflow->transition( $fulfillment->id(), 'problem', Actor::system(), $reason );

		if ( ! $outcome->is_success() ) {
			$this->workflow->transition( $fulfillment->id(), 'cancelled', Actor::system(), $reason );
		}
	}

	/**
	 * Flags a fulfillment into `problem`, with no fallback to cancellation —
	 * used by the two paths the architecture never gave an automatic-cancel
	 * setting to (a partial refund, an item change).
	 *
	 * @param int    $order_id Order the fulfillment belongs to.
	 * @param string $reason   Reason recorded on the transition.
	 */
	private function flag_problem( int $order_id, string $reason ): void {
		if ( BridgeReentrancyGuard::is_active() ) {
			return;
		}

		$fulfillment = $this->fulfillments->find_by_order_id( $order_id );

		if ( null === $fulfillment ) {
			return;
		}

		$this->workflow->transition( $fulfillment->id(), 'problem', Actor::system(), $reason );
	}

	/**
	 * Diffs the live order's line items against what was snapshotted at
	 * intake, flagging `problem` with a minimal summary if anything
	 * material changed.
	 *
	 * @param int $order_id Order whose items were saved.
	 */
	private function flag_item_changes( int $order_id ): void {
		if ( BridgeReentrancyGuard::is_active() ) {
			return;
		}

		$fulfillment = $this->fulfillments->find_by_order_id( $order_id );

		if ( null === $fulfillment ) {
			return;
		}

		$order = $this->orders->find( $order_id );

		if ( null === $order ) {
			return;
		}

		$stored = array();
		foreach ( $this->items->find_for_fulfillment( $fulfillment->id() ) as $item ) {
			$stored[ $item->order_item_id() ] = $item->qty_ordered();
		}

		$live = array();
		foreach ( $order->items() as $line ) {
			$live[ $line->order_item_id() ] = $line->quantity();
		}

		$added            = array_values( array_diff( array_keys( $live ), array_keys( $stored ) ) );
		$removed          = array_values( array_diff( array_keys( $stored ), array_keys( $live ) ) );
		$quantity_changes = array();

		foreach ( array_intersect( array_keys( $stored ), array_keys( $live ) ) as $order_item_id ) {
			if ( $stored[ $order_item_id ] !== $live[ $order_item_id ] ) {
				$quantity_changes[ $order_item_id ] = array( $stored[ $order_item_id ], $live[ $order_item_id ] );
			}
		}

		if ( array() === $added && array() === $removed && array() === $quantity_changes ) {
			return;
		}

		$this->workflow->transition( $fulfillment->id(), 'problem', Actor::system(), $this->diff_summary( $added, $removed, $quantity_changes ) );
	}

	/**
	 * Builds the minimal, PII-safe diff summary recorded as the transition
	 * reason — order-item ids and quantities only.
	 *
	 * @param array<int, int>                $added            Order item ids present on the live order but not at intake.
	 * @param array<int, int>                $removed          Order item ids present at intake but not on the live order.
	 * @param array<int, array{0:int,1:int}> $quantity_changes Order item id => [old qty, new qty].
	 */
	private function diff_summary( array $added, array $removed, array $quantity_changes ): string {
		$parts = array();

		if ( array() !== $added ) {
			$parts[] = 'added order_item_id(s): ' . implode( ',', $added );
		}

		if ( array() !== $removed ) {
			$parts[] = 'removed order_item_id(s): ' . implode( ',', $removed );
		}

		foreach ( $quantity_changes as $order_item_id => $change ) {
			$parts[] = "order_item_id {$order_item_id} quantity {$change[0]}->{$change[1]}";
		}

		return 'Order items changed after intake: ' . implode( '; ', $parts ) . '.';
	}

	/**
	 * Runs `$action`, logging (never throwing — invariant I10) on any
	 * failure so a bridge error never breaks WC order administration.
	 *
	 * @param callable():void $action Action to run.
	 */
	private function safely( callable $action ): void {
		try {
			$action();
		} catch ( Throwable $exception ) {
			$this->log( $exception->getMessage() );
		}
	}

	/**
	 * Records an inbound-observer failure without ever surfacing it to the
	 * merchant editing the order.
	 *
	 * @param string $message Failure detail.
	 */
	private function log( string $message ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->error(
			"Inbound refund/cancel observer failed: {$message}",
			array( 'source' => 'mp-commerce-fulfillment' )
		);
	}
}
