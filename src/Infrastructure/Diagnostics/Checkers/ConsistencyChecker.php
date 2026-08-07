<?php
/**
 * Lightweight consistency probes.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Diagnostics\Checkers;

use MPCF\Application\Diagnostics\CheckCategory;
use MPCF\Application\Diagnostics\Checker;
use MPCF\Application\Diagnostics\CheckResult;
use MPCF\Infrastructure\Database\WpdbDiagnosticsReader;

/**
 * Bounded orphan / relationship checks (counts only).
 */
final class ConsistencyChecker implements Checker {

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
		return 'consistency';
	}

	/**
	 * Checker category for grouping.
	 */
	public function category(): string {
		return CheckCategory::CONSISTENCY;
	}

	/**
	 * Runs diagnostic checks.
	 *
	 * @return list<CheckResult>
	 */
	public function run(): array {
		$c       = $this->reader->consistency_counts();
		$results = array();

		$results[] = $this->count_result( 'consistency.orphan_items', $c['orphan_items'], 'Orphan fulfillment_items rows' );
		$results[] = $this->count_result( 'consistency.orphan_shipments', $c['orphan_shipments'], 'Orphan shipments rows' );
		$results[] = $this->count_result( 'consistency.orphan_packages', $c['orphan_packages'], 'Orphan packages rows' );
		$results[] = $this->count_result( 'consistency.orphan_wave_members', $c['orphan_wave_members'], 'Wave members missing wave or fulfillment' );

		if ( $c['shipped_without_shipment'] > 0 ) {
			$results[] = CheckResult::warn(
				'consistency.shipped_without_shipment',
				CheckCategory::CONSISTENCY,
				sprintf( '%d shipped fulfillments lack a shipment row.', $c['shipped_without_shipment'] ),
				'Report only — no automatic repair (business interpretation).',
				'Investigate manually; do not force-ship via repair.',
				false,
				array( 'count' => $c['shipped_without_shipment'] )
			);
		} else {
			$results[] = CheckResult::pass( 'consistency.shipped_without_shipment', CheckCategory::CONSISTENCY, 'No shipped fulfillments without shipments.' );
		}

		return $results;
	}

	/**
	 * Maps a non-zero count to fail, zero to pass.
	 *
	 * @param string $id    Check id.
	 * @param int    $count Row count.
	 * @param string $label Human label.
	 */
	private function count_result( string $id, int $count, string $label ): CheckResult {
		if ( 0 === $count ) {
			return CheckResult::pass( $id, CheckCategory::CONSISTENCY, $label . ': none.' );
		}

		return CheckResult::fail(
			$id,
			CheckCategory::CONSISTENCY,
			sprintf( '%s: %d.', $label, $count ),
			'Validation only — review before any manual cleanup.',
			'Run: wp mpcf validate consistency',
			false,
			array( 'count' => $count )
		);
	}
}
