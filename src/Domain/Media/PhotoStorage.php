<?php
/**
 * Port for protected package-photo filesystem storage (ADR-0004).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Media;

use DateTimeImmutable;

/**
 * Writes and resolves relative paths under `uploads/mpcf/photos/…`.
 * Never registers files in the WordPress media library.
 *
 * Implementations return {@see \MPCF\Infrastructure\Files\PhotoStorageResult}.
 */
interface PhotoStorage {

	/**
	 * Writes canonical + thumbnail bytes atomically and returns paths.
	 *
	 * @param int               $fulfillment_id  Fulfillment id (path component).
	 * @param string            $canonical_bytes Canonical JPEG bytes.
	 * @param string            $thumb_bytes     Thumbnail JPEG bytes.
	 * @param string            $extension       File extension without dot (e.g. "jpg").
	 * @param DateTimeImmutable $now             Capture timestamp (year/month path).
	 * @return \MPCF\Infrastructure\Files\PhotoStorageResult
	 */
	public function write_pair(
		int $fulfillment_id,
		string $canonical_bytes,
		string $thumb_bytes,
		string $extension,
		DateTimeImmutable $now
	);

	/**
	 * Deletes a relative artifact when it belongs to the photo root.
	 *
	 * @param string $relative_path Relative path under uploads basedir.
	 */
	public function delete_relative( string $relative_path ): bool;

	/**
	 * Resolves a relative path to an absolute path under the photo root.
	 *
	 * @param string $relative_path Relative path under uploads basedir.
	 */
	public function absolute_path( string $relative_path ): ?string;

	/**
	 * Whether the relative path is under the protected photo root.
	 *
	 * @param string $relative_path Relative path under uploads basedir.
	 */
	public function belongs_to_photo_root( string $relative_path ): bool;
}
