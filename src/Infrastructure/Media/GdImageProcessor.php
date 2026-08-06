<?php
/**
 * GD-backed package photo processing pipeline (processing_version = 1).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Media;

use InvalidArgumentException;
use MPCF\Domain\Media\ImageProcessor;
use MPCF\Domain\Media\ProcessedImage;
use RuntimeException;

/**
 * Decodes JPEG/PNG/WebP, normalizes EXIF orientation, resizes, strips
 * metadata by re-encoding, and produces a canonical JPEG + thumbnail.
 */
final class GdImageProcessor implements ImageProcessor {

	/**
	 * Pipeline version recorded on every produced artifact.
	 */
	public const PROCESSING_VERSION = 1;

	/**
	 * Default longest-edge limit for the canonical image.
	 */
	public const DEFAULT_MAX_EDGE = 2000;

	/**
	 * Decompression-bomb guard: width × height must not exceed this.
	 */
	public const MAX_PIXELS = 40000000;

	/**
	 * Thumbnail longest-edge limit.
	 */
	public const THUMB_MAX_EDGE = 320;

	/**
	 * Canonical JPEG quality.
	 */
	public const CANONICAL_QUALITY = 85;

	/**
	 * Thumbnail JPEG quality.
	 */
	public const THUMB_QUALITY = 75;

	/**
	 * Accepted source MIME types.
	 *
	 * @var list<string>
	 */
	private const ACCEPTED_MIMES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Longest-edge limit for the canonical image.
	 *
	 * @var int
	 */
	private int $max_edge_px;

	/**
	 * Builds the processor.
	 *
	 * @param int $max_edge_px Longest-edge limit (defaults to {@see DEFAULT_MAX_EDGE}).
	 * @throws RuntimeException When the GD extension is unavailable.
	 */
	public function __construct( int $max_edge_px = self::DEFAULT_MAX_EDGE ) {
		if ( ! extension_loaded( 'gd' ) ) {
			throw new RuntimeException( 'The GD extension is required for package photo processing.' );
		}

		if ( $max_edge_px < 1 ) {
			throw new InvalidArgumentException( 'max_edge_px must be at least 1.' );
		}

		$this->max_edge_px = $max_edge_px;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws InvalidArgumentException When the source is rejected.
	 * @throws RuntimeException         When processing fails.
	 */
	public function process( string $source_bytes, string $declared_mime ): ProcessedImage {
		if ( ! extension_loaded( 'gd' ) ) {
			throw new RuntimeException( 'The GD extension is required for package photo processing.' );
		}

		$mime = strtolower( trim( explode( ';', $declared_mime, 2 )[0] ) );

		if ( ! in_array( $mime, self::ACCEPTED_MIMES, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Unsupported image MIME "%s".', $declared_mime ) );
		}

		if ( '' === $source_bytes ) {
			throw new InvalidArgumentException( 'Image bytes must not be empty.' );
		}

		$info = @getimagesizefromstring( $source_bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probe malformed uploads without raising warnings.

		if ( ! is_array( $info ) || ! isset( $info[0], $info[1], $info['mime'] ) ) {
			throw new InvalidArgumentException( 'Unable to decode image dimensions.' );
		}

		$src_w = (int) $info[0];
		$src_h = (int) $info[1];

		if ( $src_w < 1 || $src_h < 1 ) {
			throw new InvalidArgumentException( 'Image has zero dimensions.' );
		}

		if ( ( $src_w * $src_h ) > self::MAX_PIXELS ) {
			throw new InvalidArgumentException( 'Image exceeds the decompression-bomb pixel limit.' );
		}

		$detected = strtolower( (string) $info['mime'] );

		if ( ! in_array( $detected, self::ACCEPTED_MIMES, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Detected MIME "%s" is not accepted.', $detected ) );
		}

		$image = @imagecreatefromstring( $source_bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Reject malformed payloads cleanly.

		if ( false === $image ) {
			throw new InvalidArgumentException( 'Unable to decode image bytes.' );
		}

		try {
			if ( 'image/jpeg' === $detected ) {
				$image = $this->apply_exif_orientation( $image, $source_bytes );
			}

			$width  = imagesx( $image );
			$height = imagesy( $image );

			if ( false === $width || false === $height || $width < 1 || $height < 1 ) {
				throw new RuntimeException( 'Processed image has invalid dimensions.' );
			}

			$image  = $this->resize_longest_edge( $image, $this->max_edge_px );
			$width  = imagesx( $image );
			$height = imagesy( $image );

			$canonical   = $this->encode_jpeg( $image, self::CANONICAL_QUALITY );
			$thumb_source = $this->duplicate_image( $image );
			$thumb_img    = $this->resize_longest_edge( $thumb_source, self::THUMB_MAX_EDGE );
			$thumb        = $this->encode_jpeg( $thumb_img, self::THUMB_QUALITY );
			imagedestroy( $thumb_img );
		} finally {
			if ( isset( $image ) && is_object( $image ) ) {
				imagedestroy( $image );
			}
		}

		return ProcessedImage::create(
			$canonical,
			$thumb,
			'image/jpeg',
			(int) $width,
			(int) $height,
			hash( 'sha256', $canonical ),
			self::PROCESSING_VERSION,
			strlen( $canonical )
		);
	}

	/**
	 * Applies EXIF Orientation into pixels when exif_read_data is available.
	 *
	 * @param \GdImage $image        Source image resource.
	 * @param string   $source_bytes Original JPEG bytes (for EXIF).
	 * @return \GdImage
	 */
	private function apply_exif_orientation( $image, string $source_bytes ) {
		if ( ! function_exists( 'exif_read_data' ) ) {
			return $image;
		}

		$exif = @exif_read_data( 'data://image/jpeg;base64,' . base64_encode( $source_bytes ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- EXIF is optional; missing/corrupt tags must not fail capture.

		if ( ! is_array( $exif ) || ! isset( $exif['Orientation'] ) ) {
			return $image;
		}

		$orientation = (int) $exif['Orientation'];

		switch ( $orientation ) {
			case 2:
				if ( function_exists( 'imageflip' ) ) {
					imageflip( $image, IMG_FLIP_HORIZONTAL );
				}
				break;
			case 3:
				$rotated = imagerotate( $image, 180, 0 );
				if ( false !== $rotated ) {
					imagedestroy( $image );
					$image = $rotated;
				}
				break;
			case 4:
				if ( function_exists( 'imageflip' ) ) {
					imageflip( $image, IMG_FLIP_VERTICAL );
				}
				break;
			case 5:
				if ( function_exists( 'imageflip' ) ) {
					imageflip( $image, IMG_FLIP_VERTICAL );
				}
				$rotated = imagerotate( $image, -90, 0 );
				if ( false !== $rotated ) {
					imagedestroy( $image );
					$image = $rotated;
				}
				break;
			case 6:
				$rotated = imagerotate( $image, -90, 0 );
				if ( false !== $rotated ) {
					imagedestroy( $image );
					$image = $rotated;
				}
				break;
			case 7:
				if ( function_exists( 'imageflip' ) ) {
					imageflip( $image, IMG_FLIP_HORIZONTAL );
				}
				$rotated = imagerotate( $image, -90, 0 );
				if ( false !== $rotated ) {
					imagedestroy( $image );
					$image = $rotated;
				}
				break;
			case 8:
				$rotated = imagerotate( $image, 90, 0 );
				if ( false !== $rotated ) {
					imagedestroy( $image );
					$image = $rotated;
				}
				break;
		}

		return $image;
	}

	/**
	 * Duplicates a GD image so thumbnail resizing cannot free the canonical.
	 *
	 * @param \GdImage $image Source image.
	 * @return \GdImage
	 */
	private function duplicate_image( $image ) {
		$width  = imagesx( $image );
		$height = imagesy( $image );
		$copy   = imagecreatetruecolor( $width, $height );

		if ( false === $copy ) {
			throw new RuntimeException( 'Unable to allocate image duplicate.' );
		}

		imagealphablending( $copy, true );
		imagesavealpha( $copy, false );
		$white = imagecolorallocate( $copy, 255, 255, 255 );
		if ( false !== $white ) {
			imagefilledrectangle( $copy, 0, 0, $width, $height, $white );
		}

		if ( ! imagecopy( $copy, $image, 0, 0, 0, 0, $width, $height ) ) {
			imagedestroy( $copy );
			throw new RuntimeException( 'Unable to duplicate image.' );
		}

		return $copy;
	}

	/**
	 * Resizes so the longest edge is at most `$max_edge` (no upscaling).
	 *
	 * @param \GdImage $image    Source image.
	 * @param int      $max_edge Maximum longest edge.
	 * @return \GdImage
	 */
	private function resize_longest_edge( $image, int $max_edge ) {
		$width  = imagesx( $image );
		$height = imagesy( $image );
		$longest = max( $width, $height );

		if ( $longest <= $max_edge ) {
			return $image;
		}

		$scale  = $max_edge / $longest;
		$new_w  = max( 1, (int) round( $width * $scale ) );
		$new_h  = max( 1, (int) round( $height * $scale ) );
		$resized = imagecreatetruecolor( $new_w, $new_h );

		if ( false === $resized ) {
			throw new RuntimeException( 'Unable to allocate resized image buffer.' );
		}

		imagealphablending( $resized, true );
		imagesavealpha( $resized, false );
		$white = imagecolorallocate( $resized, 255, 255, 255 );
		if ( false !== $white ) {
			imagefilledrectangle( $resized, 0, 0, $new_w, $new_h, $white );
		}

		if ( ! imagecopyresampled( $resized, $image, 0, 0, 0, 0, $new_w, $new_h, $width, $height ) ) {
			imagedestroy( $resized );
			throw new RuntimeException( 'Unable to resize image.' );
		}

		imagedestroy( $image );

		return $resized;
	}

	/**
	 * Encodes a GD image as JPEG bytes.
	 *
	 * @param \GdImage $image   Source image.
	 * @param int      $quality JPEG quality 0–100.
	 * @throws RuntimeException When encoding fails.
	 */
	private function encode_jpeg( $image, int $quality ): string {
		ob_start();
		$ok = imagejpeg( $image, null, $quality );
		$bytes = (string) ob_get_clean();

		if ( ! $ok || '' === $bytes ) {
			throw new RuntimeException( 'Unable to encode JPEG.' );
		}

		return $bytes;
	}
}
