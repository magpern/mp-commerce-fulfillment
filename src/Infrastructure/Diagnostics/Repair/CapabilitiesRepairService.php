<?php
/**
 * Re-grants missing MPCF capabilities/roles via Capabilities::activate().
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Diagnostics\Repair;

use MPCF\Application\Diagnostics\MaintenanceAuditor;
use MPCF\Capabilities;

/**
 * Bounded capabilities repair — idempotent grant_missing only.
 */
final class CapabilitiesRepairService {

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
	 * Ensures roles/caps match current Capabilities definitions.
	 *
	 * @param bool $yes When false, dry-run only.
	 */
	public function repair( bool $yes ): RepairResult {
		$before  = $this->snapshot();
		$changes = array();

		$lead = get_role( Capabilities::ROLE_LEAD );
		if ( null === $lead ) {
			$changes[] = 'Create role ' . Capabilities::ROLE_LEAD;
		} else {
			foreach ( Capabilities::all() as $cap ) {
				if ( ! $lead->has_cap( $cap ) ) {
					$changes[] = 'Grant ' . $cap . ' to ' . Capabilities::ROLE_LEAD;
				}
			}
		}

		$op = get_role( Capabilities::ROLE_OPERATOR );
		if ( null === $op ) {
			$changes[] = 'Create role ' . Capabilities::ROLE_OPERATOR;
		} else {
			foreach ( Capabilities::operator() as $cap ) {
				if ( ! $op->has_cap( $cap ) ) {
					$changes[] = 'Grant ' . $cap . ' to ' . Capabilities::ROLE_OPERATOR;
				}
			}
		}

		$after = $before;
		if ( $yes && array() !== $changes ) {
			Capabilities::activate();
			$after = $this->snapshot();
			$this->auditor->record(
				'repair.capabilities',
				array(
					'changes' => count( $changes ),
					'before'  => $before,
					'after'   => $after,
				)
			);
		}

		$applied = $yes && array() !== $changes;
		$summary = array() === $changes
			? 'Roles and capabilities already match definitions.'
			: ( $yes
				? sprintf( 'Applied %d capability repair step(s).', count( $changes ) )
				: sprintf( 'Dry-run: would apply %d capability repair step(s). Pass --yes to apply.', count( $changes ) ) );

		return new RepairResult( 'capabilities', ! $yes, $applied, $summary, $before, $after, $changes );
	}

	/**
	 * Role capability snapshot for before/after reporting.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot(): array {
		$out = array();
		foreach ( Capabilities::roles() as $role_name ) {
			$role              = get_role( $role_name );
			$out[ $role_name ] = null === $role ? array() : array_keys( array_filter( $role->capabilities ) );
		}

		return $out;
	}
}
