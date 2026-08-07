<?php
/**
 * RepairResult DTO.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Diagnostics;

use MPCF\Infrastructure\Diagnostics\Repair\RepairResult;
use PHPUnit\Framework\TestCase;

/**
 * Structured repair outcome.
 */
final class RepairResultTest extends TestCase {

	public function test_to_array_exposes_dry_run_gate(): void {
		$result = new RepairResult(
			'schedules',
			true,
			false,
			'Dry-run',
			array( 'a' => false ),
			array( 'a' => false ),
			array( 'would schedule a' )
		);

		$data = $result->to_array();
		self::assertTrue( $data['dry_run'] );
		self::assertFalse( $data['applied'] );
		self::assertSame( 'schedules', $data['target'] );
		self::assertCount( 1, $data['changes'] );
	}
}
