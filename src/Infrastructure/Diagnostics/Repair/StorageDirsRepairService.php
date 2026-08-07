<?php
/**
 * Ensures protected storage directories and deny stubs exist.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Diagnostics\Repair;

use MPCF\Application\Diagnostics\MaintenanceAuditor;
use MPCF\Infrastructure\Files\ProtectedPhotoStore;

/**
 * Creates uploads/mpcf/{photos,documents} and .htaccess deny stubs.
 */
final class StorageDirsRepairService {

	/**
	 * Builds the repair service.
	 *
	 * @param MaintenanceAuditor $auditor Maintenance audit writer.
	 */
	public function __construct(
		private MaintenanceAuditor $auditor
	) {
	}

	/**
	 * Ensures storage roots exist.
	 *
	 * @param bool $yes When false, dry-run only.
	 */
	public function repair( bool $yes ): RepairResult {
		$uploads = wp_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		if ( '' === $base || ! empty( $uploads['error'] ) ) {
			return new RepairResult(
				'storage-dirs',
				! $yes,
				false,
				'Uploads directory unavailable; cannot repair storage dirs.',
				array( 'error' => (string) ( $uploads['error'] ?? '' ) ),
				array(),
				array()
			);
		}

		$root = $base . '/' . ProtectedPhotoStore::ROOT_SEGMENT;
		$dirs = array(
			$root,
			$root . '/photos',
			$root . '/documents',
		);

		$before  = array();
		$after   = array();
		$changes = array();

		foreach ( $dirs as $dir ) {
			$exists         = is_dir( $dir );
			$before[ $dir ] = $exists;
			if ( ! $exists ) {
				$changes[] = 'Create directory: ' . $dir;
				if ( $yes ) {
					if ( ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Repair mirrors Protected*Store roots.
						$after[ $dir ] = false;
						continue;
					}
				}
			}
			$after[ $dir ] = $yes ? is_dir( $dir ) : $exists;
		}

		$htaccess       = $root . '/.htaccess';
		$before['deny'] = is_file( $htaccess );
		if ( ! is_file( $htaccess ) ) {
			$changes[] = 'Write deny .htaccess under ' . $root;
			if ( $yes && is_dir( $root ) ) {
				file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- One-time deny stub.
					$htaccess,
					"# MPCF protected media (ADR-0004)\nDeny from all\n"
				);
			}
		}
		$after['deny'] = $yes ? is_file( $htaccess ) : $before['deny'];

		$applied = $yes && array() !== $changes;
		if ( $applied ) {
			$this->auditor->record(
				'repair.storage_dirs',
				array(
					'before'  => $this->sanitize_paths( $before ),
					'after'   => $this->sanitize_paths( $after ),
					'changes' => count( $changes ),
				)
			);
		}

		$summary = array() === $changes
			? 'Storage directories and deny stub already present.'
			: ( $yes
				? sprintf( 'Applied %d storage repair step(s).', count( $changes ) )
				: sprintf( 'Dry-run: would apply %d storage repair step(s). Pass --yes to apply.', count( $changes ) ) );

		return new RepairResult( 'storage-dirs', ! $yes, $applied, $summary, $before, $after, $changes );
	}

	/**
	 * Strips absolute paths from map keys for safe CLI output.
	 *
	 * @param array<string, mixed> $map Path-keyed map.
	 * @return array<string, mixed>
	 */
	private function sanitize_paths( array $map ): array {
		$out = array();
		foreach ( $map as $k => $v ) {
			$out[ is_string( $k ) ? basename( $k ) : (string) $k ] = $v;
		}

		return $out;
	}
}
