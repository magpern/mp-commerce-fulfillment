<?php
/**
 * Unit tests for ScanEventLabels.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Admin;

use MPCF\Admin\ScanEventLabels;
use PHPUnit\Framework\TestCase;

/**
 * Timeline copy for scan audit events.
 */
final class ScanEventLabelsTest extends TestCase {

	public function test_describes_pick_pack_and_correct(): void {
		self::assertStringContainsString(
			'Scanned pick',
			(string) ScanEventLabels::describe( 'scan.item_picked', array( 'item_id' => 3, 'qty_picked' => 2 ) )
		);
		self::assertStringContainsString(
			'Scanned pack',
			(string) ScanEventLabels::describe( 'scan.item_packed', array( 'item_id' => 3, 'qty_packed' => 1 ) )
		);
		self::assertStringContainsString(
			'Undid',
			(string) ScanEventLabels::describe( 'scan.corrected', array( 'item_id' => 3, 'mode' => 'picking' ) )
		);
		self::assertNull( ScanEventLabels::describe( 'items.picked', array() ) );
	}
}
