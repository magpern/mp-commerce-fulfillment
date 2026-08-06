<?php
/**
 * Tests for Code 128 encoding and SVG rendering.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Documents\Barcode;

use MPCF\Documents\Barcode\Code128Encoder;
use MPCF\Documents\Barcode\Code128SvgRenderer;
use MPCF\Documents\Barcode\DocumentBarcodeMarkup;
use PHPUnit\Framework\TestCase;

/**
 * Part IX.14 barcode rendering — deterministic, escaped, no deps.
 */
final class Code128EncoderTest extends TestCase {

	public function test_encode_widths_is_deterministic(): void {
		$a = Code128Encoder::encode_widths( 'MPCF:F:12' );
		$b = Code128Encoder::encode_widths( 'MPCF:F:12' );

		self::assertSame( $a, $b );
		self::assertGreaterThan( 10, Code128Encoder::module_count( $a ) );
	}

	public function test_rejects_non_ascii(): void {
		$this->expectException( \InvalidArgumentException::class );
		Code128Encoder::encode_widths( 'café' );
	}

	public function test_svg_contains_escaped_payload_and_rects(): void {
		$svg = Code128SvgRenderer::render( 'MPCF:F:12' );

		self::assertStringContainsString( '<svg', $svg );
		self::assertStringContainsString( '<rect', $svg );
		self::assertStringContainsString( 'MPCF:F:12', $svg );
		self::assertStringNotContainsString( '<script', $svg );
	}

	public function test_svg_escapes_attribute_special_characters(): void {
		$svg = Code128SvgRenderer::render( 'A&B"C' );

		self::assertStringContainsString( 'A&amp;B&quot;C', $svg );
	}

	public function test_document_markup_includes_human_fallback(): void {
		$html = DocumentBarcodeMarkup::render_block( 'MPCF:F:12', '#1001' );

		self::assertStringContainsString( 'data-mpcf-barcode-payload="MPCF:F:12"', $html );
		self::assertStringContainsString( 'mpcf-barcode-human', $html );
		self::assertStringContainsString( '#1001', $html );
	}
}
