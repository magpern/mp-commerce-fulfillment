<?php
/**
 * Queue-ageing bucket constants (not merchant settings).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\Analytics;

/**
 * Frozen buckets: 0–1h, 1–4h, 4–24h, 1–3d, >3d.
 */
final class QueueAgeingBuckets {

	public const KEY_0_1H = '0_1h';

	public const KEY_1_4H = '1_4h';

	public const KEY_4_24H = '4_24h';

	public const KEY_1_3D = '1_3d';

	public const KEY_GT_3D = 'gt_3d';

	/**
	 * Upper exclusive bounds in seconds; last bucket has null (unbounded).
	 *
	 * @return list<array{key: string, max_seconds: int|null}>
	 */
	public static function definitions(): array {
		return array(
			array(
				'key'         => self::KEY_0_1H,
				'max_seconds' => 3600,
			),
			array(
				'key'         => self::KEY_1_4H,
				'max_seconds' => 14400,
			),
			array(
				'key'         => self::KEY_4_24H,
				'max_seconds' => 86400,
			),
			array(
				'key'         => self::KEY_1_3D,
				'max_seconds' => 259200,
			),
			array(
				'key'         => self::KEY_GT_3D,
				'max_seconds' => null,
			),
		);
	}

	/**
	 * Assigns age-in-seconds to a bucket key.
	 *
	 * @param int $age_seconds Age in current state.
	 */
	public static function bucket_for_age( int $age_seconds ): string {
		$age = max( 0, $age_seconds );

		foreach ( self::definitions() as $def ) {
			$max = $def['max_seconds'];
			if ( null === $max || $age < $max ) {
				return $def['key'];
			}
		}

		return self::KEY_GT_3D;
	}

	/**
	 * Zeroed counts for every bucket key.
	 *
	 * @return array<string, int>
	 */
	public static function empty_counts(): array {
		$out = array();
		foreach ( self::definitions() as $def ) {
			$out[ $def['key'] ] = 0;
		}

		return $out;
	}
}
