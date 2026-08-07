<?php
/**
 * Bounded top-N reason aggregation.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\Analytics;

/**
 * Prevents unbounded reason cardinality from exploding rollups.
 */
final class TopNAggregator {

	public const DEFAULT_LIMIT = 10;

	/**
	 * Returns the top-N reasons by count, descending.
	 *
	 * @param array<string, int> $tallies Reason => count.
	 * @param int                $limit   Max rows.
	 * @return list<array{reason: string, count: int}>
	 */
	public static function top( array $tallies, int $limit = self::DEFAULT_LIMIT ): array {
		if ( $limit < 1 ) {
			return array();
		}

		arsort( $tallies, SORT_NUMERIC );

		$out   = array();
		$count = 0;
		foreach ( $tallies as $reason => $n ) {
			if ( $count >= $limit ) {
				break;
			}
			$reason = (string) $reason;
			if ( '' === $reason ) {
				$reason = '(unknown)';
			}
			$out[] = array(
				'reason' => $reason,
				'count'  => (int) $n,
			);
			++$count;
		}

		return $out;
	}
}
