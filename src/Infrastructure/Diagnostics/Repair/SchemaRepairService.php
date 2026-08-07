<?php
/**
 * Idempotent schema catch-up via Migrator.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Diagnostics\Repair;

use MPCF\Application\Diagnostics\MaintenanceAuditor;
use MPCF\Infrastructure\Database\Migrator;

/**
 * Re-runs maybe_migrate(); never drops tables.
 */
final class SchemaRepairService {

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
	 * Applies pending migrator steps when behind TARGET.
	 *
	 * @param bool $yes When false, dry-run only.
	 */
	public function repair( bool $yes ): RepairResult {
		$current = (int) get_option( Migrator::OPTION, 0 );
		$target  = Migrator::TARGET;
		$before  = array(
			'db_version' => $current,
			'target'     => $target,
		);
		$changes = array();

		if ( $current < $target ) {
			$changes[] = sprintf( 'Advance mpcf_db_version from %d to %d via Migrator::maybe_migrate().', $current, $target );
		}

		$after = $before;
		if ( $yes && array() !== $changes ) {
			( new Migrator() )->maybe_migrate();
			$after['db_version'] = (int) get_option( Migrator::OPTION, 0 );
			$this->auditor->record(
				'repair.schema',
				array(
					'before' => $before,
					'after'  => $after,
				)
			);
		}

		$applied = $yes && array() !== $changes;
		$summary = array() === $changes
			? sprintf( 'Schema already at target %d.', $target )
			: ( $yes
				? sprintf( 'Schema repair applied; now at %d.', (int) $after['db_version'] )
				: sprintf( 'Dry-run: would migrate from %d to %d. Pass --yes to apply.', $current, $target ) );

		return new RepairResult( 'schema', ! $yes, $applied, $summary, $before, $after, $changes );
	}
}
