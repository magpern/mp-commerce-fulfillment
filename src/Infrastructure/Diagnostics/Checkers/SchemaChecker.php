<?php
/**
 * Schema / migrator checks.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Diagnostics\Checkers;

use MPCF\Application\Diagnostics\CheckCategory;
use MPCF\Application\Diagnostics\Checker;
use MPCF\Application\Diagnostics\CheckResult;
use MPCF\Infrastructure\Database\Migrator;
use MPCF\Infrastructure\Database\Schema;
use MPCF\Infrastructure\Database\WpdbDiagnosticsReader;

/**
 * Verifies mpcf_db_version and required tables.
 */
final class SchemaChecker implements Checker {

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
		return 'schema';
	}

	/**
	 * Checker category for grouping.
	 */
	public function category(): string {
		return CheckCategory::SCHEMA;
	}

	/**
	 * Runs diagnostic checks.
	 *
	 * @return list<CheckResult>
	 */
	public function run(): array {
		$results = array();
		$current = (int) get_option( Migrator::OPTION, 0 );
		$target  = Migrator::TARGET;

		if ( $current === $target ) {
			$results[] = CheckResult::pass(
				'schema.migrator_version',
				CheckCategory::SCHEMA,
				sprintf( 'Schema version %d matches migrator TARGET.', $current ),
				array(
					'current' => $current,
					'target'  => $target,
				)
			);
		} elseif ( $current < $target ) {
			$results[] = CheckResult::fail(
				'schema.migrator_version',
				CheckCategory::SCHEMA,
				sprintf( 'Schema version %d is behind TARGET %d.', $current, $target ),
				'',
				'Run: wp mpcf repair schema --yes',
				true,
				array(
					'current' => $current,
					'target'  => $target,
				)
			);
		} else {
			$results[] = CheckResult::warn(
				'schema.migrator_version',
				CheckCategory::SCHEMA,
				sprintf( 'Schema version %d is ahead of this build TARGET %d.', $current, $target ),
				'A newer plugin build may have run, or the option was edited manually.',
				'Upgrade the plugin to a build whose TARGET >= current, or investigate.',
				false,
				array(
					'current' => $current,
					'target'  => $target,
				)
			);
		}

		foreach ( Schema::all_tables() as $table ) {
			if ( $this->reader->prefixed_table_exists( $table ) ) {
				$results[] = CheckResult::pass( 'schema.table.' . $table, CheckCategory::SCHEMA, sprintf( 'Table %s exists.', $table ) );
			} else {
				$results[] = CheckResult::fail(
					'schema.table.' . $table,
					CheckCategory::SCHEMA,
					sprintf( 'Required table %s is missing.', $table ),
					'',
					'Run: wp mpcf repair schema --yes',
					true,
					array( 'table' => $table )
				);
			}
		}

		return $results;
	}
}
