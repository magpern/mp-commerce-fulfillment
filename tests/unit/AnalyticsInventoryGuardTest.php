<?php
/**
 * Structural guard: Analytics packages must not couple to inventory/receiving.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADR-0007 for M9 analytics surface.
 */
final class AnalyticsInventoryGuardTest extends TestCase {

	private const FORBIDDEN = array(
		'wc-inventory-overview',
		'InventoryOverview',
		'PurchaseOrder',
		'GoodsReceipt',
		'StockLedger',
		'receiving',
		'wc_get_product_stock',
		'update_product_stock',
	);

	/**
	 * @return list<string>
	 */
	private function roots(): array {
		$base = dirname( __DIR__, 2 ) . '/src';
		return array(
			$base . '/Engine/Analytics',
			$base . '/Application/Analytics',
			$base . '/Api/Rest/AnalyticsController.php',
			$base . '/Admin/AnalyticsPage.php',
			$base . '/Cli/AnalyticsCommand.php',
			$base . '/Infrastructure/Database/WpdbAnalyticsEventSource.php',
			$base . '/Infrastructure/Database/WpdbAnalyticsDailyRepository.php',
			$base . '/Infrastructure/Database/WpdbAnalyticsDiagnosticsReader.php',
			$base . '/Infrastructure/Scheduling/AnalyticsRollupScheduler.php',
		);
	}

	public function test_analytics_packages_have_no_inventory_coupling(): void {
		$violations = array();
		foreach ( $this->roots() as $root ) {
			if ( is_dir( $root ) ) {
				$globbed = glob( $root . '/*.php' );
				$files   = false === $globbed ? array() : $globbed;
			} else {
				$files = is_file( $root ) ? array( $root ) : array();
			}
			foreach ( $files as $file ) {
				$contents = (string) file_get_contents( $file );
				foreach ( self::FORBIDDEN as $needle ) {
					if ( false !== stripos( $contents, $needle ) ) {
						$violations[] = basename( $file ) . ':' . $needle;
					}
				}
			}
		}
		self::assertSame( array(), $violations );
	}
}
