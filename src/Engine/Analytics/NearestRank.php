<?php
/**
 * Deterministic nearest-rank percentile (Part XI binding).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\Analytics;

/**
 * Percentiles use a deterministic nearest-rank algorithm.
 *
 * For a non-empty list of N samples sorted ascending, the p-th percentile
 * (0 < p ≤ 100) is the value at rank `ceil(p/100 * N)` (1-indexed).
 */
final class NearestRank {

	/**
	 * Returns the nearest-rank percentile, or null for empty/invalid input.
	 *
	 * @param list<float|int> $samples    Unsorted samples.
	 * @param float           $percentile Percent in (0, 100].
	 */
	public static function percentile( array $samples, float $percentile ): ?float {
		if ( array() === $samples ) {
			return null;
		}

		if ( $percentile <= 0.0 || $percentile > 100.0 ) {
			return null;
		}

		$sorted = array_values( $samples );
		sort( $sorted, SORT_NUMERIC );

		$n    = count( $sorted );
		$rank = (int) ceil( ( $percentile / 100.0 ) * $n );
		$rank = max( 1, min( $n, $rank ) );

		return (float) $sorted[ $rank - 1 ];
	}
}
