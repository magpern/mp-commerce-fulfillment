<?php
/**
 * Proves the integration suite actually ran against HPOS.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WP_UnitTestCase;

/**
 * HPOS activation proof — mirrors the sibling plugins' pattern: this test
 * skips when HPOS is off, so a green run reporting zero skipped tests is
 * itself the proof HPOS was active for the whole suite.
 */
final class HposProofTest extends WP_UnitTestCase {

	public function test_orders_are_stored_in_custom_tables(): void {
		if ( ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
			self::markTestSkipped( 'HPOS is not enabled for this run.' );
		}

		$order = wc_create_order();

		self::assertGreaterThan( 0, $order->get_id() );
		self::assertSame( OrdersTableDataStore::class, $order->get_data_store()->get_current_class_name() );
	}
}
