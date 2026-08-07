<?php
/**
 * Counter metric calculator (family A).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\Analytics;

/**
 * Pure merger/normalizer for counter snapshots. Gathering stays in the
 * AnalyticsEventSource; this class keeps rollup shape deterministic.
 */
final class CounterCalculator {

	/**
	 * Canonical empty counters payload.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty(): array {
		return array(
			'fulfillments'  => array(
				'created' => 0,
				'packed'  => 0,
				'shipped' => 0,
			),
			'waves'         => array(
				'completed'              => 0,
				'abandoned'              => 0,
				'member_sum'             => 0,
				'item_sum'               => 0,
				'line_sum'               => 0,
				'duration_sum_seconds'   => 0.0,
				'paused_sum_seconds'     => 0.0,
				'completion_pct_sum'     => 0.0,
				'completion_pct_samples' => 0,
			),
			'scans'         => array(
				'total'       => 0,
				'corrections' => 0,
				'failures'    => 0,
			),
			'photos'        => array(
				'captured' => 0,
				'purged'   => 0,
			),
			'notifications' => array(
				'sent'       => 0,
				'failed'     => 0,
				'suppressed' => 0,
			),
			'documents'     => array(
				'rendered'  => 0,
				'reprinted' => 0,
			),
			'exceptions'    => array(
				'state_entries' => 0,
			),
			'top_reasons'   => array(
				'rejection'    => array(),
				'guard'        => array(),
				'scan'         => array(),
				'notification' => array(),
			),
		);
	}

	/**
	 * Merges a partial counters payload into the canonical shape.
	 *
	 * @param array<string, mixed> $raw Partial counters from a source reader.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $raw ): array {
		$base = self::empty();

		foreach ( array( 'fulfillments', 'waves', 'scans', 'photos', 'notifications', 'documents', 'exceptions' ) as $group ) {
			if ( ! isset( $raw[ $group ] ) || ! is_array( $raw[ $group ] ) ) {
				continue;
			}
			foreach ( $base[ $group ] as $key => $default ) {
				if ( array_key_exists( $key, $raw[ $group ] ) ) {
					$base[ $group ][ $key ] = is_float( $default )
						? (float) $raw[ $group ][ $key ]
						: (int) $raw[ $group ][ $key ];
				}
			}
		}

		if ( isset( $raw['top_reasons'] ) && is_array( $raw['top_reasons'] ) ) {
			foreach ( array( 'rejection', 'guard', 'scan', 'notification' ) as $family ) {
				$tallies = array();
				$src     = $raw['top_reasons'][ $family ] ?? array();
				if ( is_array( $src ) ) {
					foreach ( $src as $row ) {
						if ( is_array( $row ) && isset( $row['reason'], $row['count'] ) ) {
							$tallies[ (string) $row['reason'] ] = (int) $row['count'];
						}
					}
					if ( array() === $tallies && self::is_assoc_int_map( $src ) ) {
						foreach ( $src as $reason => $count ) {
							$tallies[ (string) $reason ] = (int) $count;
						}
					}
				}
				$base['top_reasons'][ $family ] = TopNAggregator::top( $tallies );
			}
		}

		return $base;
	}

	/**
	 * Derived wave averages for API/DTO presentation.
	 *
	 * @param array<string, mixed> $waves Wave counter block.
	 * @return array<string, float|null>
	 */
	public static function wave_derived( array $waves ): array {
		$completed = (int) ( $waves['completed'] ?? 0 );
		$abandoned = (int) ( $waves['abandoned'] ?? 0 );
		$terminal  = $completed + $abandoned;

		$avg = static function ( float $sum, int $n ): ?float {
			return $n > 0 ? $sum / $n : null;
		};

		return array(
			'avg_members'          => $avg( (float) ( $waves['member_sum'] ?? 0 ), $completed ),
			'avg_items'            => $avg( (float) ( $waves['item_sum'] ?? 0 ), $completed ),
			'avg_lines'            => $avg( (float) ( $waves['line_sum'] ?? 0 ), $completed ),
			'avg_duration_seconds' => $avg( (float) ( $waves['duration_sum_seconds'] ?? 0 ), $completed ),
			'avg_paused_seconds'   => $avg( (float) ( $waves['paused_sum_seconds'] ?? 0 ), $terminal > 0 ? $terminal : $completed ),
			'avg_completion_pct'   => $avg( (float) ( $waves['completion_pct_sum'] ?? 0 ), (int) ( $waves['completion_pct_samples'] ?? 0 ) ),
			'abandoned_rate'       => $terminal > 0 ? ( $abandoned / $terminal ) : null,
		);
	}

	/**
	 * Whether `$src` looks like a reason=>count associative map.
	 *
	 * @param array<mixed> $src Candidate tallies.
	 */
	private static function is_assoc_int_map( array $src ): bool {
		foreach ( $src as $k => $v ) {
			if ( is_int( $k ) && is_array( $v ) ) {
				return false;
			}
			if ( ! is_int( $v ) && ! is_float( $v ) ) {
				return false;
			}
		}

		return array() !== $src;
	}
}
