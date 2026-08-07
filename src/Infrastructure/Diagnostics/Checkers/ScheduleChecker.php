<?php
/**
 * Action Scheduler / recurring job checks.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Diagnostics\Checkers;

use MPCF\Application\Diagnostics\CheckCategory;
use MPCF\Application\Diagnostics\Checker;
use MPCF\Application\Diagnostics\CheckResult;
use MPCF\Infrastructure\Database\WpdbDiagnosticsReader;
use MPCF\Infrastructure\Scheduling\AnalyticsRollupScheduler;
use MPCF\Infrastructure\Scheduling\PhotoRetentionScheduler;

/**
 * Verifies expected AS hooks are scheduled once.
 */
final class ScheduleChecker implements Checker {

	/**
	 * Builds the checker.
	 *
	 * @param WpdbDiagnosticsReader $reader Diagnostics SQL reader.
	 */
	public function __construct(
		private WpdbDiagnosticsReader $reader = new WpdbDiagnosticsReader()
	) {
	}

	/**
	 * Stable checker identifier.
	 */
	public function id(): string {
		return 'schedule';
	}

	/**
	 * Checker category for grouping.
	 */
	public function category(): string {
		return CheckCategory::SCHEDULE;
	}

	/**
	 * Runs diagnostic checks.
	 *
	 * @return list<CheckResult>
	 */
	public function run(): array {
		$results = array();

		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			$results[] = CheckResult::fail(
				'schedule.action_scheduler',
				CheckCategory::SCHEDULE,
				'Action Scheduler is not available.',
				'The required commerce platform normally provides Action Scheduler.',
				'Activate the commerce platform or install Action Scheduler.',
				false
			);
			return $results;
		}

		$results[] = CheckResult::pass( 'schedule.action_scheduler', CheckCategory::SCHEDULE, 'Action Scheduler is available.' );

		$hooks = array(
			PhotoRetentionScheduler::HOOK  => PhotoRetentionScheduler::AS_GROUP,
			AnalyticsRollupScheduler::HOOK => AnalyticsRollupScheduler::AS_GROUP,
		);

		foreach ( $hooks as $hook => $group ) {
			$scheduled = as_has_scheduled_action( $hook, array(), $group );
			$count     = $this->reader->action_scheduler_pending_count( $hook );
			if ( ! $scheduled ) {
				$results[] = CheckResult::fail(
					'schedule.missing.' . $hook,
					CheckCategory::SCHEDULE,
					sprintf( 'Recurring schedule for %s is missing.', $hook ),
					'',
					'Run: wp mpcf repair schedules --yes',
					true,
					array(
						'hook'    => $hook,
						'group'   => $group,
						'pending' => $count,
					)
				);
				continue;
			}
			if ( $count > 5 ) {
				$results[] = CheckResult::warn(
					'schedule.backlog.' . $hook,
					CheckCategory::SCHEDULE,
					sprintf( 'Hook %s has %d pending/in-progress actions.', $hook, $count ),
					'',
					'Inspect Action Scheduler; clear stuck jobs if needed.',
					false,
					array(
						'hook'    => $hook,
						'pending' => $count,
					)
				);
			} else {
				$results[] = CheckResult::pass(
					'schedule.ok.' . $hook,
					CheckCategory::SCHEDULE,
					sprintf( 'Schedule for %s is present.', $hook ),
					array(
						'hook'    => $hook,
						'pending' => $count,
					)
				);
			}
		}

		return $results;
	}
}
