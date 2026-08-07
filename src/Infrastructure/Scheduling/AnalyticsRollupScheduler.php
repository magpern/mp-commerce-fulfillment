<?php
/**
 * Nightly analytics ROLLUP via Action Scheduler.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Scheduling;

use MPCF\Application\Analytics\AnalyticsService;
use MPCF\Engine\Analytics\UtcDay;
use Throwable;

/**
 * Closed UTC days only. Overlap locked. No merchant "rebuild now".
 */
final class AnalyticsRollupScheduler {

	public const HOOK = 'mpcf_analytics_daily_rollup';

	public const AS_GROUP = 'mpcf';

	private const LOCK_KEY = 'mpcf_analytics_rollup_running';

	private const LOCK_TTL = 900;

	/**
	 * Analytics façade used for ROLLUP.
	 *
	 * @var AnalyticsService
	 */
	private AnalyticsService $analytics;

	/**
	 * Builds the scheduler.
	 *
	 * @param AnalyticsService $analytics Analytics façade used for ROLLUP.
	 */
	public function __construct( AnalyticsService $analytics ) {
		$this->analytics = $analytics;
	}

	/**
	 * Registers Action Scheduler hooks.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Ensures the recurring nightly action exists.
	 */
	public function ensure_scheduled(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::HOOK, array(), self::AS_GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + HOUR_IN_SECONDS,
			DAY_IN_SECONDS,
			self::HOOK,
			array(),
			self::AS_GROUP
		);
	}

	/**
	 * Rolls up yesterday (and any recent gap up to 7 closed days).
	 */
	public function run(): void {
		if ( false !== get_transient( self::LOCK_KEY ) ) {
			return;
		}

		set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

		try {
			$now  = new \DateTimeImmutable( 'now', UtcDay::timezone() );
			$from = UtcDay::start( $now )->modify( '-7 days' );
			$this->analytics->rollup_range( UtcDay::key( $from ), UtcDay::key( UtcDay::yesterday_start( $now ) ) );
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			if ( function_exists( 'error_log' ) ) {
				error_log( 'MPCF analytics rollup: ' . substr( $e->getMessage(), 0, 160 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}
}
