<?php
/**
 * Protected storage for canonical HTML document artifacts (ADR-0004).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Files;

use DateTimeImmutable;
use RuntimeException;

/**
 * Writes immutable HTML under `uploads/mpcf/documents/{yyyy}/{mm}/{fid}/…`
 * with deny rules, random filenames, atomic writes, and post-write integrity
 * checks. Never registers files in the media library.
 *
 * Relative paths only are returned for persistence — never an arbitrary
 * absolute path from callers.
 */
final class ProtectedDocumentStore {

	/**
	 * Directory segment under the uploads basedir.
	 */
	public const ROOT_SEGMENT = 'mpcf';

	/**
	 * Documents subdirectory under the protected root.
	 */
	public const DOCUMENTS_SEGMENT = 'documents';

	/**
	 * MIME type for stored HTML.
	 */
	public const MIME_HTML = 'text/html; charset=UTF-8';

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
	 * Absolute path to the documents root.
	 */
	public function documents_root(): string {
		return $this->protected_root() . '/' . self::DOCUMENTS_SEGMENT;
	}

	/**
	 * Writes HTML atomically and returns integrity metadata.
	 *
	 * @param int               $fulfillment_id Fulfillment id (path component).
	 * @param string            $doc_type       Document type key.
	 * @param string            $html           Canonical HTML bytes.
	 * @param DateTimeImmutable $now            Render timestamp (year/month path).
	 * @throws RuntimeException When the write cannot be completed safely.
	 */
	public function write( int $fulfillment_id, string $doc_type, string $html, DateTimeImmutable $now ): DocumentStorageResult {
		if ( $fulfillment_id <= 0 ) {
			throw new RuntimeException( 'Invalid fulfillment id for document storage.' );
		}

		if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', $doc_type ) ) {
			throw new RuntimeException( 'Invalid document type for storage path.' );
		}

		$this->ensure_protected_tree();

		$yyyy  = $now->format( 'Y' );
		$mm    = $now->format( 'm' );
		$token = bin2hex( random_bytes( 8 ) );

		$relative_dir = self::ROOT_SEGMENT . '/' . self::DOCUMENTS_SEGMENT . '/' . $yyyy . '/' . $mm . '/' . $fulfillment_id;
		$filename     = $doc_type . '-' . $token . '.html';
		$relative     = $relative_dir . '/' . $filename;

		if ( str_contains( $relative, '..' ) || str_contains( $relative, "\0" ) ) {
			throw new RuntimeException( 'Refusing unsafe storage path.' );
		}

		$absolute_dir = $this->uploads_basedir . '/' . $relative_dir;
		$absolute     = $absolute_dir . '/' . $filename;

		if ( ! is_dir( $absolute_dir ) && ! mkdir( $absolute_dir, 0755, true ) && ! is_dir( $absolute_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- ADR-0004 protected store owns its tree outside WP_Filesystem.
			throw new RuntimeException( 'Unable to create document storage directory.' );
		}

		$tmp = $absolute . '.tmp.' . $token;

		$written = file_put_contents( $tmp, $html, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Protected store owns its filesystem writes outside WP_Filesystem.

		if ( false === $written ) {
			$this->safe_unlink( $tmp );
			throw new RuntimeException( 'Unable to write document artifact.' );
		}

		if ( ! rename( $tmp, $absolute ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic finalize for protected artifacts.
			$this->safe_unlink( $tmp );
			$this->safe_unlink( $absolute );
			throw new RuntimeException( 'Unable to finalize document artifact.' );
		}

		chmod( $absolute, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Explicit permissions for protected artifacts.

		$bytes  = strlen( $html );
		$sha256 = hash( 'sha256', $html );

		if ( ! is_file( $absolute ) || ! is_readable( $absolute ) ) {
			$this->safe_unlink( $absolute );
			throw new RuntimeException( 'Document artifact missing after write.' );
		}

		$on_disk = file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Integrity verification of the file just written.

		if ( ! is_string( $on_disk ) || hash( 'sha256', $on_disk ) !== $sha256 || strlen( $on_disk ) !== $bytes ) {
			$this->safe_unlink( $absolute );
			throw new RuntimeException( 'Document artifact failed integrity verification.' );
		}

		$resolved = $this->absolute_path( $relative );

		if ( null === $resolved ) {
			$this->safe_unlink( $absolute );
			throw new RuntimeException( 'Document path escaped the protected root.' );
		}

		return new DocumentStorageResult( $relative, $resolved, $bytes, $sha256, self::MIME_HTML );
	}

	/**
	 * Deletes a relative artifact when safe (compensation for DB failure).
	 *
	 * @param string $relative_path Relative path previously returned by {@see write()}.
	 */
	public function delete_relative( string $relative_path ): bool {
		$absolute = $this->absolute_path( $relative_path );

		if ( null === $absolute ) {
			return false;
		}

		return $this->safe_unlink( $absolute );
	}

	/**
	 * Resolves a relative path to an absolute path under the protected root.
	 *
	 * @param string $relative_path Relative path under uploads basedir.
	 */
	public function absolute_path( string $relative_path ): ?string {
		$relative_path = ltrim( str_replace( '\\', '/', $relative_path ), '/' );

		if ( '' === $relative_path || str_contains( $relative_path, '..' ) || str_contains( $relative_path, "\0" ) ) {
			return null;
		}

		if ( ! str_starts_with( $relative_path, self::ROOT_SEGMENT . '/' . self::DOCUMENTS_SEGMENT . '/' ) ) {
			return null;
		}

		$candidate = $this->uploads_basedir . '/' . $relative_path;
		$real      = realpath( $candidate );

		if ( false === $real ) {
			// File may not exist yet / just deleted — still validate prefix without realpath of file.
			$docs_real = realpath( $this->documents_root() );

			if ( false === $docs_real ) {
				return null;
			}

			$normalized = $this->uploads_basedir . '/' . $relative_path;

			if ( ! str_starts_with( $normalized, $docs_real ) && ! str_starts_with( $normalized, $this->documents_root() ) ) {
				return null;
			}

			return $normalized;
		}

		$docs_real = realpath( $this->documents_root() );

		if ( false === $docs_real || ! str_starts_with( $real, $docs_real . DIRECTORY_SEPARATOR ) ) {
			return null;
		}

		return $real;
	}

	/**
	 * Ensures deny rules exist under the protected root.
	 *
	 * @throws RuntimeException When the protected directories cannot be created.
	 */
	private function ensure_protected_tree(): void {
		$root = $this->protected_root();
		$docs = $this->documents_root();

		foreach ( array( $root, $docs ) as $dir ) {
			if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- ADR-0004 protected store owns its tree outside WP_Filesystem.
				throw new RuntimeException( 'Unable to create protected upload root.' );
			}
		}

		$htaccess = $root . '/.htaccess';
		if ( ! is_file( $htaccess ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- One-time deny rule seed.
				$htaccess,
				"# MPCF protected media (ADR-0004)\nDeny from all\n"
			);
		}

		$index = $root . '/index.html';
		if ( ! is_file( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Directory listing deterrent.
		}

		$docs_index = $docs . '/index.html';
		if ( ! is_file( $docs_index ) ) {
			file_put_contents( $docs_index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Directory listing deterrent.
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
