<?php
/**
 * GdImageProcessor tests (skipped when GD is unavailable).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Infrastructure\Media;

use InvalidArgumentException;
use MPCF\Infrastructure\Media\GdImageProcessor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pipeline v1: decode, resize, re-encode, hash.
 */
final class GdImageProcessorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! extension_loaded( 'gd' ) ) {
			$this->markTestSkipped( 'GD extension is not loaded.' );
		}
	}

	public function test_processes_png_into_canonical_jpeg_and_thumb(): void {
		$processor = new GdImageProcessor( 200 );
		$source    = $this->make_png( 400, 300 );
		$result    = $processor->process( $source, 'image/png' );

		self::assertSame( 'image/jpeg', $result->mime() );
		self::assertSame( GdImageProcessor::PROCESSING_VERSION, $result->processing_version() );
		self::assertSame( hash( 'sha256', $result->canonical_bytes() ), $result->sha256() );
		self::assertSame( strlen( $result->canonical_bytes() ), $result->bytes() );
		self::assertLessThanOrEqual( 200, max( $result->width(), $result->height() ) );
		self::assertNotSame( '', $result->thumb_bytes() );

		$info = getimagesizefromstring( $result->canonical_bytes() );
		self::assertIsArray( $info );
		self::assertSame( 'image/jpeg', $info['mime'] );
	}

	public function test_rejects_unsupported_mime(): void {
		$processor = new GdImageProcessor();

		$this->expectException( InvalidArgumentException::class );
		$processor->process( 'not-an-image', 'image/gif' );
	}

	public function test_rejects_malformed_bytes(): void {
		$processor = new GdImageProcessor();

		$this->expectException( InvalidArgumentException::class );
		$processor->process( 'definitely-not-an-image', 'image/jpeg' );
	}

	public function test_process_requires_gd(): void {
		if ( extension_loaded( 'gd' ) ) {
			self::assertInstanceOf( GdImageProcessor::class, new GdImageProcessor() );
			return;
		}

		$processor = new GdImageProcessor();
		$this->expectException( RuntimeException::class );
		$processor->process( 'x', 'image/jpeg' );
	}

	/**
	 * Builds a tiny in-memory PNG.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 */
	private function make_png( int $width, int $height ): string {
		$image = imagecreatetruecolor( $width, $height );
		self::assertNotFalse( $image );
		$color = imagecolorallocate( $image, 10, 20, 30 );
		self::assertNotFalse( $color );
		imagefilledrectangle( $image, 0, 0, $width, $height, $color );
		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();
		imagedestroy( $image );

		return $bytes;
	}
}
