<?php
/**
 * Capacity / scale observations (informational thresholds).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Diagnostics\Checkers;

use MPCF\Application\Diagnostics\CheckCategory;
use MPCF\Application\Diagnostics\Checker;
use MPCF\Application\Diagnostics\CheckResult;
use MPCF\Engine\Analytics\RollupVersion;
use MPCF\Infrastructure\Database\Schema;
use MPCF\Infrastructure\Database\WpdbAnalyticsDailyRepository;
use MPCF\Infrastructure\Database\WpdbDiagnosticsReader;

/**
 * Table counts and analytics rollup freshness — warn only at large scale.
 */
final class CapacityChecker implements Checker {

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
		return 'capacity';
	}

	/**
	 * Checker category for grouping.
	 */
	public function category(): string {
		return CheckCategory::CAPACITY;
	}

	/**
	 * Runs diagnostic checks.
	 *
	 * @return list<CheckResult>
	 */
	public function run(): array {
		$c       = $this->reader->capacity_counts();
		$results = array();

		$results[] = CheckResult::pass(
			'capacity.fulfillments',
			CheckCategory::CAPACITY,
			sprintf( 'Fulfillments: %d.', $c['fulfillments'] ),
			array( 'count' => $c['fulfillments'] )
		);
		$results[] = CheckResult::pass(
			'capacity.events',
			CheckCategory::CAPACITY,
			sprintf( 'Events: %d.', $c['events'] ),
			array( 'count' => $c['events'] )
		);

		if ( $c['fulfillments'] >= 50000 ) {
			$results[] = CheckResult::warn(
				'capacity.fulfillments_scale',
				CheckCategory::CAPACITY,
				'Fulfillment count is at or above the 50k baseline scale.',
				'Monitor queue/analytics timings; see docs/ops/CAPACITY.md.',
				'Review capacity guidance and archival policy.',
				false,
				array( 'count' => $c['fulfillments'] )
			);
		}

		$results[] = CheckResult::pass(
			'capacity.open_queue',
			CheckCategory::CAPACITY,
			sprintf( 'Open-ish fulfillments: %d.', $c['open_queue'] ),
			array( 'count' => $c['open_queue'] )
		);

		if ( null !== $c['oldest_open'] ) {
			$results[] = CheckResult::pass(
				'capacity.oldest_open',
				CheckCategory::CAPACITY,
				'Oldest open fulfillment recorded.',
				array( 'created_at' => $c['oldest_open'] )
			);
		}

		if ( $this->reader->table_exists( Schema::ANALYTICS_DAILY ) ) {
			$repo     = new WpdbAnalyticsDailyRepository();
			$obsolete = $repo->count_obsolete( RollupVersion::CURRENT );
			if ( $obsolete > 0 ) {
				$results[] = CheckResult::warn(
					'capacity.analytics_obsolete',
					CheckCategory::CAPACITY,
					sprintf( '%d analytics rollup rows have obsolete rollup_version.', $obsolete ),
					'',
					'Run: wp mpcf analytics rebuild --from=YYYY-MM-DD --to=YYYY-MM-DD',
					false,
					array( 'obsolete' => $obsolete )
				);
			} else {
				$results[] = CheckResult::pass( 'capacity.analytics_obsolete', CheckCategory::CAPACITY, 'No obsolete analytics rollup rows.' );
			}
		}

		return $results;
	}
}
