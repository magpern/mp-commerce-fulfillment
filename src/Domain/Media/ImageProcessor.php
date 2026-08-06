<?php
/**
 * Port for deterministic package-photo image processing.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Media;

/**
 * Decodes, normalizes, resizes, and re-encodes source image bytes into a
 * canonical evidence JPEG + thumbnail. Implemented in Infrastructure.
 */
interface ImageProcessor {

	/**
	 * Processes raw upload bytes into canonical evidence artifacts.
	 *
	 * @param string $source_bytes  Raw upload bytes.
	 * @param string $declared_mime Client-declared MIME (validated).
	 */
	public function process( string $source_bytes, string $declared_mime ): ProcessedImage;
}
