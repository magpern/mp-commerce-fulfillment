<?php
/**
 * Result of the image processing pipeline.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Media;

/**
 * Immutable value object produced by {@see ImageProcessor}.
 */
final class ProcessedImage {

	/**
	 * Canonical JPEG bytes (evidence artifact).
	 *
	 * @var string
	 */
	private string $canonical_bytes;

	/**
	 * Gallery thumbnail JPEG bytes.
	 *
	 * @var string
	 */
	private string $thumb_bytes;

	/**
	 * MIME of the canonical artifact.
	 *
	 * @var string
	 */
	private string $mime;

	/**
	 * Canonical width in pixels.
	 *
	 * @var int
	 */
	private int $width;

	/**
	 * Canonical height in pixels.
	 *
	 * @var int
	 */
	private int $height;

	/**
	 * SHA-256 of the canonical bytes.
	 *
	 * @var string
	 */
	private string $sha256;

	/**
	 * Pipeline version that produced these bytes.
	 *
	 * @var int
	 */
	private int $processing_version;

	/**
	 * Byte length of the canonical artifact.
	 *
	 * @var int
	 */
	private int $bytes;

	/**
	 * Assembles a processed image. Use {@see create()}.
	 *
	 * @param string $canonical_bytes     Canonical JPEG bytes.
	 * @param string $thumb_bytes         Thumbnail JPEG bytes.
	 * @param string $mime                Canonical MIME.
	 * @param int    $width               Canonical width.
	 * @param int    $height              Canonical height.
	 * @param string $sha256              SHA-256 of canonical bytes.
	 * @param int    $processing_version  Pipeline version.
	 * @param int    $bytes               Canonical byte length.
	 */
	private function __construct(
		string $canonical_bytes,
		string $thumb_bytes,
		string $mime,
		int $width,
		int $height,
		string $sha256,
		int $processing_version,
		int $bytes
	) {
		$this->canonical_bytes    = $canonical_bytes;
		$this->thumb_bytes        = $thumb_bytes;
		$this->mime               = $mime;
		$this->width              = $width;
		$this->height             = $height;
		$this->sha256             = $sha256;
		$this->processing_version = $processing_version;
		$this->bytes              = $bytes;
	}

	/**
	 * Builds a processed-image value object.
	 *
	 * @param string $canonical_bytes    Canonical JPEG bytes.
	 * @param string $thumb_bytes        Thumbnail JPEG bytes.
	 * @param string $mime               Canonical MIME.
	 * @param int    $width              Canonical width.
	 * @param int    $height             Canonical height.
	 * @param string $sha256             SHA-256 of canonical bytes.
	 * @param int    $processing_version Pipeline version.
	 * @param int    $bytes              Canonical byte length.
	 */
	public static function create(
		string $canonical_bytes,
		string $thumb_bytes,
		string $mime,
		int $width,
		int $height,
		string $sha256,
		int $processing_version,
		int $bytes
	): self {
		return new self(
			$canonical_bytes,
			$thumb_bytes,
			$mime,
			$width,
			$height,
			$sha256,
			$processing_version,
			$bytes
		);
	}

	/**
	 * Canonical JPEG bytes.
	 */
	public function canonical_bytes(): string {
		return $this->canonical_bytes;
	}

	/**
	 * Thumbnail JPEG bytes.
	 */
	public function thumb_bytes(): string {
		return $this->thumb_bytes;
	}

	/**
	 * Canonical MIME type.
	 */
	public function mime(): string {
		return $this->mime;
	}

	/**
	 * Canonical width in pixels.
	 */
	public function width(): int {
		return $this->width;
	}

	/**
	 * Canonical height in pixels.
	 */
	public function height(): int {
		return $this->height;
	}

	/**
	 * SHA-256 of the canonical bytes.
	 */
	public function sha256(): string {
		return $this->sha256;
	}

	/**
	 * Pipeline version.
	 */
	public function processing_version(): int {
		return $this->processing_version;
	}

	/**
	 * Canonical byte length.
	 */
	public function bytes(): int {
		return $this->bytes;
	}
}
