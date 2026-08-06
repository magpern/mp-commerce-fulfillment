<?php
/**
 * PhotoKind unit tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Media;

use InvalidArgumentException;
use MPCF\Domain\Media\PhotoKind;
use PHPUnit\Framework\TestCase;

/**
 * Allow-list tests for package photography kinds.
 */
final class PhotoKindTest extends TestCase {

	public function test_accepts_contents_and_package(): void {
		self::assertTrue( PhotoKind::is_valid( PhotoKind::CONTENTS ) );
		self::assertTrue( PhotoKind::is_valid( PhotoKind::PACKAGE ) );
		self::assertFalse( PhotoKind::is_valid( 'label' ) );
	}

	public function test_assert_valid_throws_for_unknown_kind(): void {
		$this->expectException( InvalidArgumentException::class );
		PhotoKind::assert_valid( 'unknown' );
	}
}
