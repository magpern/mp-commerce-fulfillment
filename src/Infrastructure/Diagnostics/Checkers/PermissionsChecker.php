<?php
/**
 * Capability / role checks.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Diagnostics\Checkers;

use MPCF\Application\Diagnostics\CheckCategory;
use MPCF\Application\Diagnostics\Checker;
use MPCF\Application\Diagnostics\CheckResult;
use MPCF\Capabilities;

/**
 * Ensures custom roles and lead caps exist.
 */
final class PermissionsChecker implements Checker {

	/**
	 * Stable checker identifier.
	 */
	public function id(): string {
		return 'permissions';
	}

	/**
	 * Checker category for grouping.
	 */
	public function category(): string {
		return CheckCategory::PERMISSIONS;
	}

	/**
	 * Runs diagnostic checks.
	 *
	 * @return list<CheckResult>
	 */
	public function run(): array {
		$results = array();

		foreach ( Capabilities::roles() as $role_name ) {
			$role = get_role( $role_name );
			if ( null === $role ) {
				$results[] = CheckResult::fail(
					'permissions.role.' . $role_name,
					CheckCategory::PERMISSIONS,
					sprintf( 'Expected role %s is missing.', $role_name ),
					'',
					'Reactivate the plugin or run Capabilities::activate().',
					false
				);
				continue;
			}
			$results[] = CheckResult::pass( 'permissions.role.' . $role_name, CheckCategory::PERMISSIONS, sprintf( 'Role %s exists.', $role_name ) );
		}

		$lead = get_role( Capabilities::ROLE_LEAD );
		if ( null !== $lead ) {
			$missing = array();
			foreach ( Capabilities::all() as $cap ) {
				if ( ! $lead->has_cap( $cap ) ) {
					$missing[] = $cap;
				}
			}
			if ( array() === $missing ) {
				$results[] = CheckResult::pass( 'permissions.lead_caps', CheckCategory::PERMISSIONS, 'Warehouse Lead has all MPCF capabilities.' );
			} else {
				$results[] = CheckResult::fail(
					'permissions.lead_caps',
					CheckCategory::PERMISSIONS,
					'Warehouse Lead is missing capabilities.',
					implode( ', ', $missing ),
					'Reactivate the plugin to grant missing capabilities.',
					false,
					array( 'missing' => $missing )
				);
			}
		}

		$admin = get_role( 'administrator' );
		if ( null !== $admin && ! $admin->has_cap( Capabilities::MANAGE_SETTINGS ) ) {
			$results[] = CheckResult::warn(
				'permissions.admin_manage_settings',
				CheckCategory::PERMISSIONS,
				'Administrator lacks mpcf_manage_settings.',
				'',
				'Reactivate the plugin.',
				false
			);
		} elseif ( null !== $admin ) {
			$results[] = CheckResult::pass( 'permissions.admin_manage_settings', CheckCategory::PERMISSIONS, 'Administrator has mpcf_manage_settings.' );
		}

		return $results;
	}
}
