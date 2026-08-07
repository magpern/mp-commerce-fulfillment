<?php
/**
 * Privacy anonymization helpers.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Infrastructure\Privacy;

use MPCF\Infrastructure\Privacy\PrivacyEraser;
use PHPUnit\Framework\TestCase;

/**
 * Constants and retained-field documentation smoke.
 */
final class PrivacyEraserTest extends TestCase {

	public function test_anonymization_tokens_are_stable(): void {
		self::assertSame( '[anonymized]', PrivacyEraser::ANON_NAME );
		self::assertSame( '[erased]', PrivacyEraser::ANON_ACTOR );
		self::assertSame( '[note erased]', PrivacyEraser::ANON_NOTE );
	}
}
