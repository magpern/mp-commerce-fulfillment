<?php
/**
 * Schedules bounded package-photo retention purge via Action Scheduler.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Scheduling;

use MPCF\Application\Photos\PhotoRetentionService;
use Throwable;

/**
 * Recurring daily purge under Action Scheduler group `mpcf` (architecture
 * D12 — never assume WP pseudo-cron). Overlap is blocked with a transient
 * lock. No merchant "Purge now" UI.
 */
final class PhotoRetentionScheduler {

	/**
	 * Action Scheduler hook / WordPress action name.
	 */
	public const HOOK = 'mpcf_purge_photo_retention';

	/**
	 * Action Scheduler group (shared with intake retries).
	 */
	public const AS_GROUP = 'mpcf';

	/**
	 * Transient key preventing overlapping runs.
	 */
	private const LOCK_KEY = 'mpcf_photo_retention_running';

	/**
	 * Lock TTL in seconds.
	 */
	private const LOCK_TTL = 900;

	/**
	 * Retention purge orchestrator.
	 *
	 * @var PhotoRetentionService
	 */
	private PhotoRetentionService $retention;

	/**
	 * Builds the scheduler.
	 *
	 * @param PhotoRetentionService $retention Retention purge orchestrator.
	 */
	public function __construct( PhotoRetentionService $retention ) {
		$this->retention = $retention;
	}

	/**
	 * Registers the action callback and ensures a recurring schedule exists.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Schedules a daily recurring action when Action Scheduler is available
	 * and no duplicate schedule exists.
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
	 * Executes one bounded purge batch. Safe to call from tests/CLI seams.
	 */
	public function run(): void {
		if ( false !== get_transient( self::LOCK_KEY ) ) {
			return;
		}

		set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

		try {
			$this->retention->purge_batch( PhotoRetentionService::DEFAULT_BATCH_SIZE );
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Bounded log; never break the shop.
			if ( function_exists( 'error_log' ) ) {
				error_log( 'MPCF photo retention: ' . substr( $e->getMessage(), 0, 160 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational failure breadcrumb.
			}
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}
}
