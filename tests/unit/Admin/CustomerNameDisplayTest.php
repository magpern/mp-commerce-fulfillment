<?php
/**
 * Unit tests for CustomerNameDisplay.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Admin;

use MPCF\Admin\CustomerNameDisplay;
use PHPUnit\Framework\TestCase;

/**
 * Empty customer snapshots must stay readable in admin lists.
 */
final class CustomerNameDisplayTest extends TestCase {

	public function test_returns_trimmed_snapshot_when_present(): void {
		self::assertSame( 'Jane Doe', CustomerNameDisplay::label( '  Jane Doe  ' ) );
	}

	public function test_returns_fallback_when_empty(): void {
		self::assertSame( 'No customer name', CustomerNameDisplay::label( '' ) );
		self::assertSame( 'No customer name', CustomerNameDisplay::label( '   ' ) );
	}
}
