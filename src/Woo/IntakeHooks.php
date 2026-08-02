<?php
/**
 * Bridges paid-order platform hooks to intake.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Woo;

use MPCF\Application\IntakeService;
use Throwable;

/**
 * Intake is synchronous first: the platform hook calls
 * {@see IntakeService::intake()} inline, in the same request, so the common
 * case (a healthy site processing one order at a time) gets a fulfillment
 * the moment the order is paid — no async latency, no dependency on a
 * scheduler tick. Action Scheduler is the *fallback*, not the primary path
 * (spike S5 in the architecture plan names it "intake fallback" for exactly
 * this reason): if the synchronous attempt fails for any reason, this class
 * schedules one retry through Action Scheduler (bundled with WooCommerce,
 * always present, invariant D12) rather than losing the order — invariant
 * I10, fulfillment never breaks the shop. The retry handler itself does not
 * reschedule on a further failure; a permanently unintakeable order (a
 * genuinely missing order id) is logged and left for `wp mpcf intake
 * backfill` rather than requeued forever.
 *
 * {@see IntakeService::intake()} is idempotent, so nothing here needs its
 * own deduplication: a duplicate `woocommerce_payment_complete` firing, or
 * both the synchronous attempt and its scheduled retry somehow both running,
 * resolve to the same single fulfillment.
 */
final class IntakeHooks {

	/**
	 * Action hook name used for the Action Scheduler fallback.
	 */
	public const RETRY_ACTION = 'mpcf_process_intake';

	/**
	 * Action Scheduler group this plugin's actions are filed under.
	 */
	private const AS_GROUP = 'mpcf';

	/**
	 * Idempotent order-to-fulfillment intake.
	 *
	 * @var IntakeService
	 */
	private IntakeService $intake;

	/**
	 * Builds the hook bridge.
	 *
	 * @param IntakeService $intake Idempotent order-to-fulfillment intake.
	 */
	public function __construct( IntakeService $intake ) {
		$this->intake = $intake;
	}

	/**
	 * Registers every hook this class owns.
	 */
	public function register(): void {
		add_action( 'woocommerce_payment_complete', array( $this, 'handle_order_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'handle_order_paid' ) );
		add_action( self::RETRY_ACTION, array( $this, 'process_scheduled_intake' ) );
	}

	/**
	 * Handles a paid-order notification from either hook (classic and Blocks
	 * checkout both fire `woocommerce_payment_complete`; some gateways and
	 * manual-order flows go straight to `processing` without it, which is
	 * why both are covered). Falls back to a scheduled retry on failure.
	 *
	 * @param int $order_id Order that was just paid.
	 */
	public function handle_order_paid( int $order_id ): void {
		if ( $this->attempt_intake( $order_id ) ) {
			return;
		}

		$this->schedule_retry( $order_id );
	}

	/**
	 * Runs the scheduled fallback retry. Does not reschedule itself on a
	 * further failure — see this class's docblock.
	 *
	 * @param int $order_id Order to retry intake for.
	 */
	public function process_scheduled_intake( int $order_id ): void {
		$this->attempt_intake( $order_id );
	}

	/**
	 * Attempts intake once, logging (never throwing — invariant I10) on any
	 * failure.
	 *
	 * @param int $order_id Order to ingest.
	 */
	private function attempt_intake( int $order_id ): bool {
		try {
			$outcome = $this->intake->intake( $order_id );
		} catch ( Throwable $exception ) {
			$this->log( $order_id, $exception->getMessage() );

			return false;
		}

		if ( $outcome->is_success() ) {
			return true;
		}

		$this->log( $order_id, (string) $outcome->failure_message() );

		return false;
	}

	/**
	 * Schedules the Action Scheduler fallback retry.
	 *
	 * @param int $order_id Order to retry intake for.
	 */
	private function schedule_retry( int $order_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			// WooCommerce always bundles Action Scheduler (D12); this branch
			// only guards against a broken/partial install, never breaking
			// the shop over a missing scheduler.
			$this->log( $order_id, 'Action Scheduler is unavailable; intake could not be scheduled for retry.' );

			return;
		}

		as_enqueue_async_action( self::RETRY_ACTION, array( 'order_id' => $order_id ), self::AS_GROUP );
	}

	/**
	 * Records an intake failure without ever surfacing it to the shopper.
	 *
	 * @param int    $order_id Order intake failed for.
	 * @param string $message  Failure detail.
	 */
	private function log( int $order_id, string $message ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->error(
			"Fulfillment intake failed for order {$order_id}: {$message}",
			array( 'source' => 'mp-commerce-fulfillment' )
		);
	}
}
