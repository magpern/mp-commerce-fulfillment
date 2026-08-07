<?php
/**
 * Queue-ageing calculator (LIVE operational state).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\Analytics;

/**
 * Buckets open fulfillments by age in current state. Code constants only.
 */
final class QueueAgeingCalculator {

	/**
	 * Summarizes open-queue ages into frozen buckets.
	 *
	 * @param array $age_seconds     Ages of open fulfillments (seconds in current state).
	 * @param int   $exception_count Open exception-state count.
	 * @return array{open_count: int, exception_count: int, buckets: array<string, int>}
	 */
	public static function summarize( array $age_seconds, int $exception_count ): array {
		$buckets = QueueAgeingBuckets::empty_counts();

		foreach ( $age_seconds as $age ) {
			$key             = QueueAgeingBuckets::bucket_for_age( (int) $age );
			$buckets[ $key ] = ( $buckets[ $key ] ?? 0 ) + 1;
		}

		return array(
			'open_count'      => count( $age_seconds ),
			'exception_count' => max( 0, $exception_count ),
			'buckets'         => $buckets,
		);
	}
}
