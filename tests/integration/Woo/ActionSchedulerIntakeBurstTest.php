<?php
/**
 * Spike S5: Action Scheduler throughput for the intake fallback path, at
 * the volume the architecture plan's own falsification test names.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Woo;

use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Woo\IntakeHooks;
use WP_UnitTestCase;

/**
 * Architecture plan spike S5: "Action Scheduler latency/throughput for
 * intake fallback ... on constrained hosts. Falsification test: seeded
 * 200-order burst ingests within 5 min with default AS tuning."
 *
 * `Woo\IntakeHooks` treats Action Scheduler as intake's *fallback* path —
 * a real checkout's synchronous attempt succeeds inline and never touches
 * the scheduler at all (proven by `IntakeHooksTest`). Proving S5 therefore
 * means exercising the fallback path itself at 200-order volume, not 200
 * ordinary checkouts (which would never reach Action Scheduler and so
 * would falsely appear to "pass" without testing anything about it): 200
 * real orders are seeded, then {@see IntakeHooks::RETRY_ACTION} is enqueued
 * directly for every one of them — the same action a synchronous failure
 * would have scheduled — and the Action Scheduler queue is drained exactly
 * as a real cron/async-request worker would, timed end to end.
 */
final class ActionSchedulerIntakeBurstTest extends WP_UnitTestCase {

	use OrderFactoryTrait;
	use CleanFulfillmentTablesTrait;

	private const ORDER_COUNT = 200;

	private const TARGET_SECONDS = 300; // Spike S5's own 5-minute falsification threshold.

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();
	}

	public function test_a_200_order_intake_fallback_burst_drains_within_the_target_window(): void {
		$product = $this->create_product();

		$order_ids = array();

		for ( $i = 0; $i < self::ORDER_COUNT; $i++ ) {
			$order = new \WC_Order();
			$order->add_product( $product, 1 );
			$order->set_billing_first_name( 'Jane' );
			$order->set_billing_last_name( 'Doe' );
			$order->set_status( 'pending' ); // Deliberately never paid — sync intake must never fire for these.
			$order->calculate_totals();
			$order->save();

			$order_ids[] = $order->get_id();
		}

		self::assertNull(
			( new WpdbFulfillmentRepository() )->find_by_order_id( $order_ids[0] ),
			'A pending, unpaid order must not already have a fulfillment before the burst starts.'
		);

		foreach ( $order_ids as $order_id ) {
			as_enqueue_async_action( IntakeHooks::RETRY_ACTION, array( 'order_id' => $order_id ), 'mpcf' );
		}

		$started = microtime( true );
		$runner  = \ActionScheduler_QueueRunner::instance();

		do {
			$processed = $runner->run();
		} while ( $processed > 0 );

		$elapsed = microtime( true ) - $started;

		self::assertLessThan(
			self::TARGET_SECONDS,
			$elapsed,
			sprintf( 'The %d-order intake fallback burst took %.1fs, exceeding spike S5\'s 5-minute target.', self::ORDER_COUNT, $elapsed )
		);

		$repository      = new WpdbFulfillmentRepository();
		$missing         = array();
		$fulfillment_ids = array();

		foreach ( $order_ids as $order_id ) {
			$fulfillment = $repository->find_by_order_id( $order_id );

			if ( null === $fulfillment ) {
				$missing[] = $order_id;
				continue;
			}

			$fulfillment_ids[] = $fulfillment->id();
		}

		self::assertSame( array(), $missing, 'Every seeded order must have produced a fulfillment once the queue drained.' );
		self::assertCount( self::ORDER_COUNT, array_unique( $fulfillment_ids ), 'Every order must have produced exactly one fulfillment, never a duplicate.' );
	}
}
