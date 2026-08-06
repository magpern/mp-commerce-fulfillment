<?php
/**
 * Result of writing a protected photo + thumbnail pair.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Files;

/**
 * Immutable write result for {@see ProtectedPhotoStore}.
 */
final class PhotoStorageResult {

	/**
	 * Relative path to the canonical JPEG under uploads basedir.
	 *
	 * @var string
	 */
	private string $relative_path;

	/**
	 * Relative path to the thumbnail JPEG.
	 *
	 * @var string
	 */
	private string $thumb_relative_path;

	/**
	 * Absolute path to the canonical JPEG.
	 *
	 * @var string
	 */
	private string $absolute_path;

	/**
	 * Absolute path to the thumbnail JPEG.
	 *
	 * @var string
	 */
	private string $thumb_absolute_path;

	/**
	 * Canonical byte length.
	 *
	 * @var int
	 */
	private int $byte_size;

	/**
	 * Thumbnail byte length.
	 *
	 * @var int
	 */
	private int $thumb_byte_size;

	/**
	 * SHA-256 of the canonical bytes.
	 *
	 * @var string
	 */
	private string $sha256;

	/**
	 * Builds a storage result.
	 *
	 * @param string $relative_path       Relative canonical path.
	 * @param string $thumb_relative_path Relative thumbnail path.
	 * @param string $absolute_path       Absolute canonical path.
	 * @param string $thumb_absolute_path Absolute thumbnail path.
	 * @param int    $byte_size           Canonical byte length.
	 * @param int    $thumb_byte_size     Thumbnail byte length.
	 * @param string $sha256              SHA-256 of canonical bytes.
	 */
	public function __construct(
		string $relative_path,
		string $thumb_relative_path,
		string $absolute_path,
		string $thumb_absolute_path,
		int $byte_size,
		int $thumb_byte_size,
		string $sha256
	) {
		$this->relative_path       = $relative_path;
		$this->thumb_relative_path = $thumb_relative_path;
		$this->absolute_path       = $absolute_path;
		$this->thumb_absolute_path = $thumb_absolute_path;
		$this->byte_size           = $byte_size;
		$this->thumb_byte_size     = $thumb_byte_size;
		$this->sha256              = $sha256;
	}

	/**
	 * Relative canonical path.
	 */
	public function relative_path(): string {
		return $this->relative_path;
	}

	/**
	 * Relative thumbnail path.
	 */
	public function thumb_relative_path(): string {
		return $this->thumb_relative_path;
	}

	/**
	 * Absolute canonical path.
	 */
	public function absolute_path(): string {
		return $this->absolute_path;
	}

	/**
	 * Absolute thumbnail path.
	 */
	public function thumb_absolute_path(): string {
		return $this->thumb_absolute_path;
	}

	/**
	 * Canonical byte length.
	 */
	public function byte_size(): int {
		return $this->byte_size;
	}

	/**
	 * Thumbnail byte length.
	 */
	public function thumb_byte_size(): int {
		return $this->thumb_byte_size;
	}

	/**
	 * SHA-256 of canonical bytes.
	 */
	public function sha256(): string {
		return $this->sha256;
	}
}
