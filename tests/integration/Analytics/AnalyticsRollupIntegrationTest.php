<?php
/**
 * Integration: analytics LIVE / ROLLUP / REBUILD against real tables.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use MPCF\Application\Analytics\AnalyticsCsvExporter;
use MPCF\Application\Analytics\AnalyticsService;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Engine\Analytics\AnalyticsEngine;
use MPCF\Engine\Analytics\RollupVersion;
use MPCF\Engine\Analytics\UtcDay;
use MPCF\Infrastructure\Database\Migrator;
use MPCF\Infrastructure\Database\WpdbAnalyticsDailyRepository;
use MPCF\Infrastructure\Database\WpdbAnalyticsDiagnosticsReader;
use MPCF\Infrastructure\Database\WpdbAnalyticsEventSource;
use MPCF\Infrastructure\SystemClock;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use WP_UnitTestCase;

/**
 * Proves rollup immutability and rebuild on MariaDB.
 */
final class AnalyticsRollupIntegrationTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;

	public function set_up(): void {
		parent::set_up();
		( new Migrator() )->migrate();
		$this->clean_fulfillment_tables();
	}

	public function test_rollup_skips_rewrite_rebuild_updates(): void {
		$engine = new AnalyticsEngine( new WpdbAnalyticsEventSource(), new WpdbAnalyticsDailyRepository() );
		$now    = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$day    = UtcDay::yesterday_start( $now );

		$first = $engine->rollup_day( $day, 1, $now );
		self::assertSame( 'written', $first['status'] );
		self::assertSame( RollupVersion::CURRENT, $first['metrics']->rollup_version() );

		$second = $engine->rollup_day( $day, 1, $now );
		self::assertSame( 'unchanged', $second['status'] );

		$rebuilt = $engine->rebuild_day( $day, 1, $now );
		self::assertSame( RollupVersion::CURRENT, $rebuilt->rollup_version() );
		self::assertSame( UtcDay::key( $day ), $rebuilt->utc_date() );
	}

	public function test_service_overview_and_csv_dto_parity(): void {
		$service = new AnalyticsService(
			new AnalyticsEngine( new WpdbAnalyticsEventSource(), new WpdbAnalyticsDailyRepository() ),
			new SystemClock(),
			StandardWorkflow::definition(),
			new WpdbAnalyticsDiagnosticsReader()
		);
		$overview = $service->overview();
		self::assertArrayHasKey( 'cards', $overview );
		self::assertArrayHasKey( 'timeline', $overview );
		self::assertArrayHasKey( 'ageing', $overview );

		$range = \MPCF\Application\Analytics\AnalyticsRange::weekly( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
		$dto   = $service->report_dto( $range );
		$csv   = ( new AnalyticsCsvExporter() )->export( $dto, AnalyticsCsvExporter::TYPE_THROUGHPUT );
		self::assertStringContainsString( 'utc_date,source,created,packed,shipped', $csv );
	}
}
