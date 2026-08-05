<?php
/**
 * Result of writing one protected document artifact.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Files;

/**
 * Immutable write result for {@see ProtectedDocumentStore}.
 */
final class DocumentStorageResult {

	/**
	 * Relative path under the uploads basedir (e.g. mpcf/documents/…).
	 *
	 * @var string
	 */
	private string $relative_path;

	/**
	 * Absolute filesystem path.
	 *
	 * @var string
	 */
	private string $absolute_path;

	/**
	 * Byte length of the written content.
	 *
	 * @var int
	 */
	private int $byte_size;

	/**
	 * SHA-256 hex digest of the content.
	 *
	 * @var string
	 */
	private string $sha256;

	/**
	 * MIME type of the stored artifact.
	 *
	 * @var string
	 */
	private string $mime_type;

	/**
	 * Builds a storage result.
	 *
	 * @param string $relative_path Relative path under uploads.
	 * @param string $absolute_path Absolute filesystem path.
	 * @param int    $byte_size     Byte length.
	 * @param string $sha256        SHA-256 digest.
	 * @param string $mime_type     MIME type.
	 */
	public function __construct(
		string $relative_path,
		string $absolute_path,
		int $byte_size,
		string $sha256,
		string $mime_type
	) {
		$this->relative_path = $relative_path;
		$this->absolute_path = $absolute_path;
		$this->byte_size     = $byte_size;
		$this->sha256        = $sha256;
		$this->mime_type     = $mime_type;
	}

	/**
	 * Relative path stored on the document record.
	 */
	public function relative_path(): string {
		return $this->relative_path;
	}

	/**
	 * Absolute filesystem path.
	 */
	public function absolute_path(): string {
		return $this->absolute_path;
	}

	/**
	 * Byte length.
	 */
	public function byte_size(): int {
		return $this->byte_size;
	}

	/**
	 * SHA-256 hex digest.
	 */
	public function sha256(): string {
		return $this->sha256;
	}

	/**
	 * MIME type.
	 */
	public function mime_type(): string {
		return $this->mime_type;
	}
}
