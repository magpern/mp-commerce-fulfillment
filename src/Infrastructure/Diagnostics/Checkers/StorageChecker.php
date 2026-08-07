<?php
/**
 * Storage path checks for protected media roots.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Diagnostics\Checkers;

use MPCF\Application\Diagnostics\CheckCategory;
use MPCF\Application\Diagnostics\Checker;
use MPCF\Application\Diagnostics\CheckResult;
use MPCF\Infrastructure\Files\ProtectedPhotoStore;

/**
 * Verifies uploads/mpcf roots exist and are writable.
 */
final class StorageChecker implements Checker {

	/**
	 * Stable checker identifier.
	 */
	public function id(): string {
		return 'storage';
	}

	/**
	 * Checker category for grouping.
	 */
	public function category(): string {
		return CheckCategory::STORAGE;
	}

	/**
	 * Runs diagnostic checks.
	 *
	 * @return list<CheckResult>
	 */
	public function run(): array {
		$results = array();
		$uploads = wp_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';

		if ( '' === $base || ! empty( $uploads['error'] ) ) {
			$results[] = CheckResult::fail(
				'storage.uploads',
				CheckCategory::STORAGE,
				'WordPress uploads directory is not available.',
				is_string( $uploads['error'] ?? null ) ? (string) $uploads['error'] : '',
				'Fix WP_CONTENT_DIR / uploads configuration.',
				false
			);
			return $results;
		}

		$root    = $base . '/' . ProtectedPhotoStore::ROOT_SEGMENT;
		$results = array_merge( $results, $this->probe_dir( 'storage.root', $root, 'MPCF root (uploads/mpcf)' ) );
		$results = array_merge( $results, $this->probe_dir( 'storage.photos', $root . '/photos', 'Photo storage root' ) );
		$results = array_merge( $results, $this->probe_dir( 'storage.documents', $root . '/documents', 'Document storage root' ) );

		$deny = $root . '/.htaccess';
		if ( is_file( $deny ) ) {
			$results[] = CheckResult::pass( 'storage.deny_file', CheckCategory::STORAGE, 'Protected-storage deny file present.', array( 'path' => $deny ) );
		} else {
			$results[] = CheckResult::warn(
				'storage.deny_file',
				CheckCategory::STORAGE,
				'No .htaccess deny file under uploads/mpcf.',
				'Apache may serve files if the web root is misconfigured; nginx must deny separately.',
				'Run: wp mpcf repair storage-dirs --yes (writes deny stubs where supported).',
				true
			);
		}

		if ( function_exists( 'disk_free_space' ) ) {
			$free = @disk_free_space( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Optional disk probe; failure is ignored.
			if ( false !== $free && $free < 50 * 1024 * 1024 ) {
				$results[] = CheckResult::warn(
					'storage.disk_free',
					CheckCategory::STORAGE,
					'Less than 50MB free on the uploads volume.',
					'',
					'Free disk space before photo/document growth continues.',
					false,
					array( 'free_bytes' => $free )
				);
			} elseif ( false !== $free ) {
				$results[] = CheckResult::pass( 'storage.disk_free', CheckCategory::STORAGE, 'Uploads volume has adequate free space.', array( 'free_bytes' => $free ) );
			}
		}

		unset( $uploads );

		return $results;
	}

	/**
	 * Probes one directory for existence and writability.
	 *
	 * @param string $id    Check id prefix.
	 * @param string $path  Absolute path.
	 * @param string $label Human label.
	 * @return list<CheckResult>
	 */
	private function probe_dir( string $id, string $path, string $label ): array {
		if ( ! is_dir( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir -- Diagnostics probe only.
			return array(
				CheckResult::fail(
					$id,
					CheckCategory::STORAGE,
					sprintf( '%s is missing.', $label ),
					$path,
					'Run: wp mpcf repair storage-dirs --yes',
					true,
					array( 'path' => $path )
				),
			);
		}
		if ( ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Diagnostics probe only.
			return array(
				CheckResult::fail(
					$id . '.writable',
					CheckCategory::STORAGE,
					sprintf( '%s is not writable.', $label ),
					$path,
					'Fix filesystem ownership/permissions for the web user.',
					false,
					array( 'path' => $path )
				),
			);
		}

		return array(
			CheckResult::pass( $id, CheckCategory::STORAGE, sprintf( '%s exists and is writable.', $label ), array( 'path' => $path ) ),
		);
	}
}
