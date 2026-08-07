<?php
/**
 * BarcodePayload type W unit tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Barcode;

use MPCF\Domain\Barcode\BarcodePayload;
use PHPUnit\Framework\TestCase;

/**
 * Additive MPCF:W parser support.
 */
final class BarcodePayloadWaveTest extends TestCase {

	public function test_wave_type_is_known_and_round_trips(): void {
		self::assertTrue( BarcodePayload::is_known_type( BarcodePayload::TYPE_WAVE ) );
		$payload = BarcodePayload::wave( 42 );
		self::assertSame( 'MPCF:W:42', $payload->encode() );

		$parsed = BarcodePayload::parse( 'MPCF:W:42' );
		self::assertTrue( $parsed->is_ok() );
		self::assertSame( BarcodePayload::TYPE_WAVE, $parsed->payload()->type() );
		self::assertSame( 42, $parsed->payload()->value() );
	}
}
