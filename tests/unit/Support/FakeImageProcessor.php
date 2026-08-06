<?php
/**
 * Deterministic ImageProcessor double for PhotoService tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Support;

use MPCF\Domain\Media\ImageProcessor;
use MPCF\Domain\Media\ProcessedImage;

/**
 * Returns fixed processed bytes without requiring GD.
 */
final class FakeImageProcessor implements ImageProcessor {

	/**
	 * Canonical bytes returned by process().
	 *
	 * @var string
	 */
	private string $canonical;

	/**
	 * Thumbnail bytes returned by process().
	 *
	 * @var string
	 */
	private string $thumb;

	/**
	 * @param string $canonical Canonical JPEG bytes.
	 * @param string $thumb     Thumbnail JPEG bytes.
	 */
	public function __construct( string $canonical = 'CANONICAL', string $thumb = 'THUMB' ) {
		$this->canonical = $canonical;
		$this->thumb     = $thumb;
	}

	/**
	 * {@inheritDoc}
	 */
	public function process( string $source_bytes, string $declared_mime ): ProcessedImage {
		unset( $source_bytes, $declared_mime );

		return ProcessedImage::create(
			$this->canonical,
			$this->thumb,
			'image/jpeg',
			100,
			80,
			hash( 'sha256', $this->canonical ),
			1,
			strlen( $this->canonical )
		);
	}
}
