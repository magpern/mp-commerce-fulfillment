<?php
/**
 * Builds the default CheckerRegistry used by doctor and Site Health.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Diagnostics;

use MPCF\Application\Diagnostics\CheckerRegistry;
use MPCF\Infrastructure\Diagnostics\Checkers\CapacityChecker;
use MPCF\Infrastructure\Diagnostics\Checkers\ConfigurationChecker;
use MPCF\Infrastructure\Diagnostics\Checkers\ConsistencyChecker;
use MPCF\Infrastructure\Diagnostics\Checkers\EnvironmentChecker;
use MPCF\Infrastructure\Diagnostics\Checkers\IntegrationChecker;
use MPCF\Infrastructure\Diagnostics\Checkers\PermissionsChecker;
use MPCF\Infrastructure\Diagnostics\Checkers\ScheduleChecker;
use MPCF\Infrastructure\Diagnostics\Checkers\SchemaChecker;
use MPCF\Infrastructure\Diagnostics\Checkers\StorageChecker;

/**
 * Single factory so CLI and Site Health never diverge.
 */
final class DefaultCheckerRegistryFactory {

	/**
	 * Populates a registry with all production checkers.
	 */
	public static function create(): CheckerRegistry {
		$registry = new CheckerRegistry();
		$registry->register( new EnvironmentChecker() );
		$registry->register( new ConfigurationChecker() );
		$registry->register( new PermissionsChecker() );
		$registry->register( new SchemaChecker() );
		$registry->register( new ConsistencyChecker() );
		$registry->register( new StorageChecker() );
		$registry->register( new ScheduleChecker() );
		$registry->register( new IntegrationChecker() );
		$registry->register( new CapacityChecker() );

		return $registry;
	}
}
