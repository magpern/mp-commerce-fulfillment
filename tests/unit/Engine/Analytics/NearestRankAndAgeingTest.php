<?php
/**
 * Unit tests for nearest-rank percentiles and ageing buckets.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Engine\Analytics;

use MPCF\Engine\Analytics\NearestRank;
use MPCF\Engine\Analytics\QueueAgeingBuckets;
use MPCF\Engine\Analytics\QueueAgeingCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Binding: deterministic nearest-rank; code-constant ageing buckets.
 */
final class NearestRankAndAgeingTest extends TestCase {

	public function test_nearest_rank_p50_p90_on_ten_samples(): void {
		// 1..10 → p50 rank ceil(0.5*10)=5 → 5; p90 rank ceil(0.9*10)=9 → 9
		$samples = array( 10, 1, 2, 3, 4, 5, 6, 7, 8, 9 );
		self::assertSame( 5.0, NearestRank::percentile( $samples, 50.0 ) );
		self::assertSame( 9.0, NearestRank::percentile( $samples, 90.0 ) );
	}

	public function test_nearest_rank_empty_is_null(): void {
		self::assertNull( NearestRank::percentile( array(), 50.0 ) );
	}

	public function test_ageing_bucket_boundaries(): void {
		self::assertSame( QueueAgeingBuckets::KEY_0_1H, QueueAgeingBuckets::bucket_for_age( 0 ) );
		self::assertSame( QueueAgeingBuckets::KEY_0_1H, QueueAgeingBuckets::bucket_for_age( 3599 ) );
		self::assertSame( QueueAgeingBuckets::KEY_1_4H, QueueAgeingBuckets::bucket_for_age( 3600 ) );
		self::assertSame( QueueAgeingBuckets::KEY_4_24H, QueueAgeingBuckets::bucket_for_age( 14400 ) );
		self::assertSame( QueueAgeingBuckets::KEY_1_3D, QueueAgeingBuckets::bucket_for_age( 86400 ) );
		self::assertSame( QueueAgeingBuckets::KEY_GT_3D, QueueAgeingBuckets::bucket_for_age( 259200 ) );
	}

	public function test_ageing_summarize_counts(): void {
		$summary = QueueAgeingCalculator::summarize( array( 100, 4000, 300000 ), 2 );
		self::assertSame( 3, $summary['open_count'] );
		self::assertSame( 2, $summary['exception_count'] );
		self::assertSame( 1, $summary['buckets'][ QueueAgeingBuckets::KEY_0_1H ] );
		self::assertSame( 1, $summary['buckets'][ QueueAgeingBuckets::KEY_1_4H ] );
		self::assertSame( 1, $summary['buckets'][ QueueAgeingBuckets::KEY_GT_3D ] );
	}
}
