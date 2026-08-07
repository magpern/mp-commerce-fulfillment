<?php
/**
 * AnalyticsEngine LIVE / ROLLUP / REBUILD behaviour.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Engine\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use MPCF\Engine\Analytics\AnalyticsEngine;
use MPCF\Engine\Analytics\DailyMetrics;
use MPCF\Engine\Analytics\RollupVersion;
use MPCF\Engine\Analytics\UtcDay;
use MPCF\Tests\Unit\Application\Doubles\InMemoryAnalyticsDailyRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryAnalyticsEventSource;
use PHPUnit\Framework\TestCase;

/**
 * Immutability and mode contracts.
 */
final class AnalyticsEngineTest extends TestCase {

	public function test_rollup_does_not_rewrite_current_version_row(): void {
		$source = new InMemoryAnalyticsEventSource();
		$daily  = new InMemoryAnalyticsDailyRepository();
		$engine = new AnalyticsEngine( $source, $daily );
		$now    = new DateTimeImmutable( '2026-08-07 12:00:00', new DateTimeZone( 'UTC' ) );
		$day    = UtcDay::yesterday_start( $now );

		$first = $engine->rollup_day( $day, 1, $now );
		self::assertSame( 'written', $first['status'] );

		$source->counters = array(
			'fulfillments' => array(
				'created' => 99,
				'packed'  => 0,
				'shipped' => 0,
			),
		);
		$second           = $engine->rollup_day( $day, 1, $now );
		self::assertSame( 'unchanged', $second['status'] );
		self::assertSame( 0, $second['metrics']->counters()['fulfillments']['created'] );
	}

	public function test_rebuild_rewrites_historical_row(): void {
		$source = new InMemoryAnalyticsEventSource();
		$daily  = new InMemoryAnalyticsDailyRepository();
		$engine = new AnalyticsEngine( $source, $daily );
		$now    = new DateTimeImmutable( '2026-08-07 12:00:00', new DateTimeZone( 'UTC' ) );
		$day    = UtcDay::yesterday_start( $now );

		$engine->rollup_day( $day, 1, $now );
		$source->counters = array(
			'fulfillments' => array(
				'created' => 42,
				'packed'  => 1,
				'shipped' => 1,
			),
		);
		$rebuilt          = $engine->rebuild_day( $day, 1, $now );
		self::assertSame( 42, $rebuilt->counters()['fulfillments']['created'] );
		self::assertSame( RollupVersion::CURRENT, $rebuilt->rollup_version() );
	}

	public function test_live_never_writes_rollup(): void {
		$source = new InMemoryAnalyticsEventSource();
		$daily  = new InMemoryAnalyticsDailyRepository();
		$engine = new AnalyticsEngine( $source, $daily );
		$now    = new DateTimeImmutable( '2026-08-07 12:00:00', new DateTimeZone( 'UTC' ) );

		$engine->live( UtcDay::start( $now ), UtcDay::end_exclusive( $now ), $now, 1, array( 'queued' ), array( 'problem' ) );
		self::assertNull( $daily->find( UtcDay::key( $now ), 1 ) );
	}

	public function test_obsolete_version_detected(): void {
		$daily = new InMemoryAnalyticsDailyRepository();
		$daily->upsert(
			new DailyMetrics( '2026-08-01', 1, 0, array(), array() ),
			'2026-08-01 01:00:00'
		);
		$engine = new AnalyticsEngine( new InMemoryAnalyticsEventSource(), $daily );
		self::assertSame( 1, $engine->count_obsolete_rows() );
	}
}
