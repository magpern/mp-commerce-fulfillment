<?php
/**
 * CSV exporter uses report DTO columns deterministically.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Analytics;

use MPCF\Application\Analytics\AnalyticsCsvExporter;
use PHPUnit\Framework\TestCase;

/**
 * Parity with AnalyticsService DTOs.
 */
final class AnalyticsCsvExporterTest extends TestCase {

	public function test_throughput_csv_header_order(): void {
		$exporter = new AnalyticsCsvExporter();
		$csv      = $exporter->export(
			array(
				'days' => array(
					array(
						'utc_date' => '2026-08-01',
						'source'   => 'rollup',
						'counters' => array(
							'fulfillments' => array(
								'created' => 1,
								'packed'  => 2,
								'shipped' => 3,
							),
							'scans'        => array( 'total' => 4 ),
							'documents'    => array( 'rendered' => 5 ),
							'photos'       => array( 'captured' => 6 ),
						),
					),
				),
			),
			AnalyticsCsvExporter::TYPE_THROUGHPUT
		);
		$lines    = explode( "\n", trim( $csv ) );
		self::assertSame( 'utc_date,source,created,packed,shipped,scans_total,docs_rendered,photos_captured', $lines[0] );
		self::assertSame( '2026-08-01,rollup,1,2,3,4,5,6', $lines[1] );
	}
}
