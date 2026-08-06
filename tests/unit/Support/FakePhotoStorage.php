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
	 * Forces the next write to fail.
	 */
	public function fail_next_write(): void {
		$this->fail_writes = true;
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
	 * {@inheritDoc}
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
		if ( ! $this->belongs_to_photo_root( $relative_path ) ) {
			return false;
		}

		unset( $this->files[ $relative_path ] );

		return true;
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
