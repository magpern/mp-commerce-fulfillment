<?php
/**
 * Protected storage for package photography evidence (ADR-0004 / Part VIII).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Files;

use DateTimeImmutable;
use MPCF\Domain\Media\PhotoStorage;
use RuntimeException;

/**
 * Writes immutable JPEG pairs under `uploads/mpcf/photos/{yyyy}/{mm}/{fid}/…`
 * with deny rules, random filenames, atomic writes, and post-write integrity
 * checks. Never registers files in the media library.
 */
final class ProtectedPhotoStore implements PhotoStorage {

	/**
	 * Directory segment under the uploads basedir.
	 */
	public const ROOT_SEGMENT = 'mpcf';

	/**
	 * Photos subdirectory under the protected root.
	 */
	public const PHOTOS_SEGMENT = 'photos';

	/**
	 * Absolute uploads basedir (no trailing slash).
	 *
	 * @var string
	 */
	private string $uploads_basedir;

	/**
	 * Builds the store against an uploads basedir.
	 *
	 * @param string|null $uploads_basedir Override for tests; null uses wp_upload_dir().
	 * @throws RuntimeException When the uploads basedir cannot be resolved.
	 */
	public function __construct( ?string $uploads_basedir = null ) {
		if ( null !== $uploads_basedir && '' !== $uploads_basedir ) {
			$this->uploads_basedir = rtrim( $uploads_basedir, '/\\' );

			return;
		}

		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$basedir = is_array( $uploads ) && isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';

		if ( '' === $basedir ) {
			throw new RuntimeException( 'Uploads basedir is unavailable.' );
		}

		$this->uploads_basedir = rtrim( $basedir, '/\\' );
	}

	/**
	 * Absolute path to the protected mpcf root.
	 */
	public function protected_root(): string {
		return $this->uploads_basedir . '/' . self::ROOT_SEGMENT;
	}

	/**
	 * Absolute path to the photos root.
	 */
	public function photos_root(): string {
		return $this->protected_root() . '/' . self::PHOTOS_SEGMENT;
	}

	/**
	 * Writes canonical + thumbnail bytes atomically and returns paths.
	 *
	 * @param int               $fulfillment_id  Fulfillment id (path component).
	 * @param string            $canonical_bytes Canonical JPEG bytes.
	 * @param string            $thumb_bytes     Thumbnail JPEG bytes.
	 * @param string            $extension       File extension without dot (e.g. "jpg").
	 * @param DateTimeImmutable $now             Capture timestamp (year/month path).
	 * @throws RuntimeException When the write cannot be completed safely.
	 */
	public function write_pair(
		int $fulfillment_id,
		string $canonical_bytes,
		string $thumb_bytes,
		string $extension,
		DateTimeImmutable $now
	): PhotoStorageResult {
		if ( $fulfillment_id <= 0 ) {
			throw new RuntimeException( 'Invalid fulfillment id for photo storage.' );
		}

		if ( 1 !== preg_match( '/^[a-z0-9]+$/', $extension ) ) {
			throw new RuntimeException( 'Invalid photo file extension.' );
		}

		if ( '' === $canonical_bytes || '' === $thumb_bytes ) {
			throw new RuntimeException( 'Canonical and thumbnail bytes must be non-empty.' );
		}

		$this->ensure_protected_tree();

		$yyyy  = $now->format( 'Y' );
		$mm    = $now->format( 'm' );
		$token = bin2hex( random_bytes( 8 ) );

		$relative_dir = self::ROOT_SEGMENT . '/' . self::PHOTOS_SEGMENT . '/' . $yyyy . '/' . $mm . '/' . $fulfillment_id;
		$filename     = $token . '.' . $extension;
		$thumb_name   = $token . '-thumb.jpg';
		$relative     = $relative_dir . '/' . $filename;
		$thumb_rel    = $relative_dir . '/' . $thumb_name;

		if ( str_contains( $relative, '..' ) || str_contains( $thumb_rel, '..' ) || str_contains( $relative, "\0" ) ) {
			throw new RuntimeException( 'Refusing unsafe storage path.' );
		}

		$absolute_dir = $this->uploads_basedir . '/' . $relative_dir;
		$absolute     = $absolute_dir . '/' . $filename;
		$thumb_abs    = $absolute_dir . '/' . $thumb_name;

		if ( ! is_dir( $absolute_dir ) && ! mkdir( $absolute_dir, 0755, true ) && ! is_dir( $absolute_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- ADR-0004 protected store owns its tree outside WP_Filesystem.
			throw new RuntimeException( 'Unable to create photo storage directory.' );
		}

		try {
			$this->atomic_write( $absolute, $canonical_bytes, $token );
			$this->atomic_write( $thumb_abs, $thumb_bytes, $token . '-thumb' );
		} catch ( RuntimeException $e ) {
			$this->safe_unlink( $absolute );
			$this->safe_unlink( $thumb_abs );
			throw $e;
		}

		$resolved       = $this->absolute_path( $relative );
		$thumb_resolved = $this->absolute_path( $thumb_rel );

		if ( null === $resolved || null === $thumb_resolved ) {
			$this->safe_unlink( $absolute );
			$this->safe_unlink( $thumb_abs );
			throw new RuntimeException( 'Photo path escaped the protected root.' );
		}

		return new PhotoStorageResult(
			$relative,
			$thumb_rel,
			$resolved,
			$thumb_resolved,
			strlen( $canonical_bytes ),
			strlen( $thumb_bytes ),
			hash( 'sha256', $canonical_bytes )
		);
	}

	/**
	 * Deletes a relative artifact when it belongs to the photo root.
	 *
	 * @param string $relative_path Relative path under uploads basedir.
	 */
	public function delete_relative( string $relative_path ): bool {
		$absolute = $this->absolute_path( $relative_path );

		if ( null === $absolute ) {
			return false;
		}

		return $this->safe_unlink( $absolute );
	}

	/**
	 * Resolves a relative path to an absolute path under the photo root.
	 *
	 * @param string $relative_path Relative path under uploads basedir.
	 */
	public function absolute_path( string $relative_path ): ?string {
		$relative_path = ltrim( str_replace( '\\', '/', $relative_path ), '/' );

		if ( '' === $relative_path || str_contains( $relative_path, '..' ) || str_contains( $relative_path, "\0" ) ) {
			return null;
		}

		$prefix = self::ROOT_SEGMENT . '/' . self::PHOTOS_SEGMENT . '/';

		if ( ! str_starts_with( $relative_path, $prefix ) ) {
			return null;
		}

		$candidate = $this->uploads_basedir . '/' . $relative_path;
		$real      = realpath( $candidate );

		if ( false === $real ) {
			$photos_real = realpath( $this->photos_root() );

			if ( false === $photos_real ) {
				return null;
			}

			$normalized = $this->uploads_basedir . '/' . $relative_path;

			if ( ! str_starts_with( $normalized, $photos_real ) && ! str_starts_with( $normalized, $this->photos_root() ) ) {
				return null;
			}

			return $normalized;
		}

		$photos_real = realpath( $this->photos_root() );

		if ( false === $photos_real || ! str_starts_with( $real, $photos_real . DIRECTORY_SEPARATOR ) ) {
			return null;
		}

		return $real;
	}

	/**
	 * Whether the relative path is under the protected photo root.
	 *
	 * @param string $relative_path Relative path under uploads basedir.
	 */
	public function belongs_to_photo_root( string $relative_path ): bool {
		return null !== $this->absolute_path( $relative_path );
	}

	/**
	 * Ensures deny rules exist under the protected root and photos segment.
	 *
	 * @throws RuntimeException When the protected directories cannot be created.
	 */
	private function ensure_protected_tree(): void {
		$root   = $this->protected_root();
		$photos = $this->photos_root();

		foreach ( array( $root, $photos ) as $dir ) {
			if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- ADR-0004 protected store owns its tree outside WP_Filesystem.
				throw new RuntimeException( 'Unable to create protected upload root.' );
			}
		}

		foreach ( array( $root, $photos ) as $dir ) {
			$htaccess = $dir . '/.htaccess';
			if ( ! is_file( $htaccess ) ) {
				file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- One-time deny rule seed.
					$htaccess,
					"# MPCF protected media (ADR-0004)\nDeny from all\n"
				);
			}

			$index = $dir . '/index.html';
			if ( ! is_file( $index ) ) {
				file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Directory listing deterrent.
			}
		}
	}

	/**
	 * Atomically writes bytes to an absolute path with integrity verification.
	 *
	 * @param string $absolute Absolute destination path.
	 * @param string $bytes    Content to write.
	 * @param string $token    Unique token for the temp filename.
	 * @throws RuntimeException When the write fails integrity checks.
	 */
	private function atomic_write( string $absolute, string $bytes, string $token ): void {
		$tmp     = $absolute . '.tmp.' . $token;
		$written = file_put_contents( $tmp, $bytes, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Protected store owns its filesystem writes outside WP_Filesystem.

		if ( false === $written ) {
			$this->safe_unlink( $tmp );
			throw new RuntimeException( 'Unable to write photo artifact.' );
		}

		if ( ! rename( $tmp, $absolute ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic finalize for protected artifacts.
			$this->safe_unlink( $tmp );
			$this->safe_unlink( $absolute );
			throw new RuntimeException( 'Unable to finalize photo artifact.' );
		}

		chmod( $absolute, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Explicit permissions for protected artifacts.

		$expected = strlen( $bytes );
		$sha256   = hash( 'sha256', $bytes );

		if ( ! is_file( $absolute ) || ! is_readable( $absolute ) ) {
			$this->safe_unlink( $absolute );
			throw new RuntimeException( 'Photo artifact missing after write.' );
		}

		$on_disk = file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Integrity verification of the file just written.

		if ( ! is_string( $on_disk ) || hash( 'sha256', $on_disk ) !== $sha256 || strlen( $on_disk ) !== $expected ) {
			$this->safe_unlink( $absolute );
			throw new RuntimeException( 'Photo artifact failed integrity verification.' );
		}
	}

	/**
	 * Unlinks a file when present.
	 *
	 * @param string $path Absolute path.
	 */
	private function safe_unlink( string $path ): bool {
		if ( ! is_file( $path ) ) {
			return true;
		}

		return unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Compensation / cleanup for protected artifacts.
	}
}
