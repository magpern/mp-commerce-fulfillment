<?php
/**
 * In-memory PhotoStorage for PhotoService tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Support;

use DateTimeImmutable;
use MPCF\Domain\Media\PhotoStorage;
use MPCF\Infrastructure\Files\PhotoStorageResult;
use RuntimeException;

/**
 * Records writes in memory; optional failure injection for compensation tests.
 */
final class FakePhotoStorage implements PhotoStorage {

	/**
	 * Relative path => bytes.
	 *
	 * @var array<string, string>
	 */
	private array $files = array();

	/**
	 * When true, write_pair throws.
	 *
	 * @var bool
	 */
	private bool $fail_writes = false;

	/**
	 * When true, the next delete_relative returns false.
	 *
	 * @var bool
	 */
	private bool $fail_deletes = false;

	/**
	 * Forces the next write to fail.
	 */
	public function fail_next_write(): void {
		$this->fail_writes = true;
	}

	/**
	 * Forces the next delete_relative to fail containment/delete.
	 */
	public function fail_next_delete(): void {
		$this->fail_deletes = true;
	}

	/**
	 * Plants bytes at a relative path for tests (must be under photo root).
	 *
	 * @param string $relative Relative path.
	 * @param string $bytes    Content.
	 */
	public function put( string $relative, string $bytes ): void {
		if ( ! $this->belongs_to_photo_root( $relative ) ) {
			throw new RuntimeException( 'Path outside photo root.' );
		}

		$this->files[ $relative ] = $bytes;
	}

	/**
	 * Whether a relative path still has bytes.
	 *
	 * @param string $relative Relative path.
	 */
	public function has( string $relative ): bool {
		return isset( $this->files[ $relative ] );
	}

	/**
	 * Writes canonical + thumbnail bytes atomically and returns paths.
	 *
	 * @param int               $fulfillment_id  Fulfillment id (path component).
	 * @param string            $canonical_bytes Canonical JPEG bytes.
	 * @param string            $thumb_bytes     Thumbnail JPEG bytes.
	 * @param string            $extension       File extension without dot (e.g. "jpg").
	 * @param DateTimeImmutable $now             Capture timestamp (year/month path).
	 * @throws RuntimeException When the write is rejected or simulated to fail.
	 */
	public function write_pair(
		int $fulfillment_id,
		string $canonical_bytes,
		string $thumb_bytes,
		string $extension,
		DateTimeImmutable $now
	) {
		if ( $this->fail_writes ) {
			$this->fail_writes = false;
			throw new RuntimeException( 'Simulated storage failure.' );
		}

		if ( $fulfillment_id <= 0 ) {
			throw new RuntimeException( 'Invalid fulfillment id.' );
		}

		$token    = bin2hex( random_bytes( 4 ) );
		$yyyy     = $now->format( 'Y' );
		$mm       = $now->format( 'm' );
		$relative = "mpcf/photos/{$yyyy}/{$mm}/{$fulfillment_id}/{$token}.{$extension}";
		$thumb    = "mpcf/photos/{$yyyy}/{$mm}/{$fulfillment_id}/{$token}-thumb.jpg";

		$this->files[ $relative ] = $canonical_bytes;
		$this->files[ $thumb ]    = $thumb_bytes;

		return new PhotoStorageResult(
			$relative,
			$thumb,
			'/tmp/' . $relative,
			'/tmp/' . $thumb,
			strlen( $canonical_bytes ),
			strlen( $thumb_bytes ),
			hash( 'sha256', $canonical_bytes )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete_relative( string $relative_path ): bool {
		if ( $this->fail_deletes ) {
			$this->fail_deletes = false;

			return false;
		}

		if ( ! $this->belongs_to_photo_root( $relative_path ) ) {
			return false;
		}

		unset( $this->files[ $relative_path ] );

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function exists_relative( string $relative_path ): bool {
		return $this->belongs_to_photo_root( $relative_path ) && isset( $this->files[ $relative_path ] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function absolute_path( string $relative_path ): ?string {
		if ( ! $this->belongs_to_photo_root( $relative_path ) || ! isset( $this->files[ $relative_path ] ) ) {
			return null;
		}

		return '/tmp/' . $relative_path;
	}

	/**
	 * {@inheritDoc}
	 */
	public function belongs_to_photo_root( string $relative_path ): bool {
		return str_starts_with( $relative_path, 'mpcf/photos/' ) && ! str_contains( $relative_path, '..' );
	}
}
