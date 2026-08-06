<?php
/**
 * Tests for BarcodePayload parsing and encoding.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Barcode;

use MPCF\Domain\Barcode\BarcodeParseResult;
use MPCF\Domain\Barcode\BarcodePayload;
use PHPUnit\Framework\TestCase;

/**
 * Part IX.2 payload contract.
 */
final class BarcodePayloadTest extends TestCase {

	public function test_encode_is_deterministic_and_versionless(): void {
		self::assertSame( 'MPCF:F:12', BarcodePayload::fulfillment( 12 )->encode() );
		self::assertSame( 'MPCF:I:99', BarcodePayload::item( 99 )->encode() );
		self::assertSame( 'MPCF:P:3', BarcodePayload::package( 3 )->encode() );
		self::assertSame( 'MPCF:PR:500', BarcodePayload::product( 500 )->encode() );
		self::assertSame( 'MPCF:V:501', BarcodePayload::variation( 501 )->encode() );
		self::assertSame( 'MPCF:1:F:12', BarcodePayload::fulfillment( 12 )->encode_versioned() );
	}

	public function test_parse_accepts_versionless_and_versioned_forms(): void {
		$a = BarcodePayload::parse( 'MPCF:F:12' );
		$b = BarcodePayload::parse( 'mpcf:1:i:99' );

		self::assertTrue( $a->is_ok() );
		self::assertSame( BarcodePayload::TYPE_FULFILLMENT, $a->payload()->type() );
		self::assertSame( 12, $a->payload()->value() );

		self::assertTrue( $b->is_ok() );
		self::assertSame( BarcodePayload::TYPE_ITEM, $b->payload()->type() );
		self::assertSame( 99, $b->payload()->value() );
		self::assertSame( 1, $b->payload()->format_version() );
	}

	public function test_parse_rejects_malformed_mpcf_shapes(): void {
		self::assertTrue( BarcodePayload::parse( 'MPCF:Z:1' )->is_malformed() );
		self::assertTrue( BarcodePayload::parse( 'MPCF:F:0' )->is_malformed() );
		self::assertTrue( BarcodePayload::parse( 'MPCF:F:abc' )->is_malformed() );
		self::assertTrue( BarcodePayload::parse( 'MPCF:F' )->is_malformed() );
		self::assertSame( BarcodeParseResult::KIND_EMPTY, BarcodePayload::parse( '   ' )->kind() );
	}

	public function test_plain_sku_is_not_namespaced(): void {
		$result = BarcodePayload::parse( ' WIDGET-1 ' );

		self::assertTrue( $result->is_plain() );
		self::assertSame( 'WIDGET-1', $result->plain() );
	}

	public function test_rejects_non_positive_factory_values(): void {
		$this->expectException( \InvalidArgumentException::class );
		BarcodePayload::fulfillment( 0 );
	}
}
