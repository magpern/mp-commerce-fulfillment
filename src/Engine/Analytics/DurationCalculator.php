<?php
/**
 * Duration / percentile calculator (family B).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\Analytics;

/**
 * Pure calculator: samples in, stats out. Independent of counters.
 */
final class DurationCalculator {

	/**
	 * Stage Timeline hop keys (Part XI).
	 *
	 * @return list<string>
	 */
	public static function hop_keys(): array {
		return array(
			'queued_to_picking',
			'picking_to_picked',
			'picked_to_packing',
			'packing_to_packed',
			'packed_to_shipped',
			'queued_to_shipped',
		);
	}

	/**
	 * Summarizes hop duration samples.
	 *
	 * @param list<float|int> $seconds Unsorted hop durations in seconds.
	 * @return array{count: int, sum: float, avg: float|null, p50: float|null, p90: float|null}
	 */
	public static function summarize( array $seconds ): array {
		$count = count( $seconds );
		if ( 0 === $count ) {
			return array(
				'count' => 0,
				'sum'   => 0.0,
				'avg'   => null,
				'p50'   => null,
				'p90'   => null,
			);
		}

		$sum = 0.0;
		foreach ( $seconds as $s ) {
			$sum += (float) $s;
		}

		return array(
			'count' => $count,
			'sum'   => $sum,
			'avg'   => $sum / $count,
			'p50'   => NearestRank::percentile( $seconds, 50.0 ),
			'p90'   => NearestRank::percentile( $seconds, 90.0 ),
		);
	}

	/**
	 * Summarizes every hop key, filling missing hops with empty stats.
	 *
	 * @param array<string, list<float|int>> $by_hop Samples keyed by hop id.
	 * @return array<string, array{count: int, sum: float, avg: float|null, p50: float|null, p90: float|null}>
	 */
	public static function summarize_hops( array $by_hop ): array {
		$out = array();
		foreach ( self::hop_keys() as $key ) {
			$out[ $key ] = self::summarize( $by_hop[ $key ] ?? array() );
		}

		return $out;
	}

	/**
	 * Empty durations payload for a rollup row.
	 *
	 * @return array<string, array{count: int, sum: float, avg: float|null, p50: float|null, p90: float|null}>
	 */
	public static function empty(): array {
		return self::summarize_hops( array() );
	}
}
