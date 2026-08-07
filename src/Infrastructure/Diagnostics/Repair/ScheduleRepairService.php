<?php
/**
 * Re-registers missing Action Scheduler recurring jobs.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Diagnostics\Repair;

use MPCF\Application\Diagnostics\MaintenanceAuditor;
use MPCF\Infrastructure\Scheduling\AnalyticsRollupScheduler;
use MPCF\Infrastructure\Scheduling\PhotoRetentionScheduler;

/**
 * Bounded schedule repair — never mass-cancels unrelated actions.
 */
final class ScheduleRepairService {

	/**
	 * Builds the repair service.
	 *
	 * @param MaintenanceAuditor $auditor Maintenance audit writer.
	 */
	public function __construct(
		private MaintenanceAuditor $auditor
	) {
	}

	/**
	 * Restores missing mpcf recurring schedules.
	 *
	 * @param bool $yes When false, dry-run only.
	 */
	public function repair( bool $yes ): RepairResult {
		$hooks = array(
			PhotoRetentionScheduler::HOOK  => array(
				'group'    => PhotoRetentionScheduler::AS_GROUP,
				'interval' => defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400,
			),
			AnalyticsRollupScheduler::HOOK => array(
				'group'    => AnalyticsRollupScheduler::AS_GROUP,
				'interval' => defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400,
			),
		);

		$before  = array();
		$changes = array();
		$after   = array();

		foreach ( $hooks as $hook => $meta ) {
			$present         = function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( $hook, array(), $meta['group'] );
			$before[ $hook ] = $present;

			if ( $present ) {
				$after[ $hook ] = true;
				continue;
			}

			$changes[] = sprintf( 'Schedule missing hook %s (group %s).', $hook, $meta['group'] );

			if ( $yes ) {
				if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
					$after[ $hook ] = false;
					continue;
				}
				$delay = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
				as_schedule_recurring_action(
					time() + $delay,
					(int) $meta['interval'],
					$hook,
					array(),
					$meta['group']
				);
				$after[ $hook ] = function_exists( 'as_has_scheduled_action' )
					&& as_has_scheduled_action( $hook, array(), $meta['group'] );
			} else {
				$after[ $hook ] = false;
			}
		}

		$applied = $yes && array() !== $changes;
		if ( $applied ) {
			$this->auditor->record(
				'repair.schedules',
				array(
					'before'  => $before,
					'after'   => $after,
					'changes' => $changes,
				)
			);
		}

		$summary = array() === $changes
			? 'All expected schedules were already present.'
			: ( $yes
				? sprintf( 'Applied %d schedule repair(s).', count( $changes ) )
				: sprintf( 'Dry-run: would repair %d missing schedule(s). Pass --yes to apply.', count( $changes ) ) );

		return new RepairResult( 'schedules', ! $yes, $applied, $summary, $before, $after, $changes );
	}
}
