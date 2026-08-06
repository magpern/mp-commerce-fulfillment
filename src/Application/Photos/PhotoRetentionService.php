<?php
/**
 * Bounded retention purge for package photography bytes.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Photos;

use MPCF\Application\EventDispatcher;
use MPCF\Domain\Clock;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\Media\PhotoRecord;
use MPCF\Domain\Media\PhotoRetentionEligibility;
use MPCF\Domain\Media\PhotoStorage;
use MPCF\Domain\Repository\EventRepository;
use MPCF\Domain\Repository\MediaRepository;
use Throwable;

/**
 * Removes canonical + thumbnail bytes for age-eligible photos, then marks
 * `purged_at` and clears relative paths. Never hard-deletes metadata rows.
 * Does not reuse {@see PhotoService::soft_delete()}.
 *
 * Residual risk: filesystem deletion and DB/audit updates are not one
 * atomic transaction. If files are removed but `mark_purged` or the audit
 * append fails, a later batch retries: missing files are treated as
 * already-removed (idempotent), then metadata is marked and audited.
 */
final class PhotoRetentionService {

	/**
	 * Default candidates per scheduled run.
	 */
	public const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Photo persistence.
	 *
	 * @var MediaRepository
	 */
	private MediaRepository $media;

	/**
	 * Protected photo filesystem.
	 *
	 * @var PhotoStorage
	 */
	private PhotoStorage $storage;

	/**
	 * Audit log persistence.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * In-process event dispatch.
	 *
	 * @var EventDispatcher
	 */
	private EventDispatcher $dispatcher;

	/**
	 * Source of "now".
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Retention months resolver (0 = indefinite).
	 *
	 * @var callable(): int
	 */
	private $retention_months;

	/**
	 * @param MediaRepository  $media             Photo persistence.
	 * @param PhotoStorage     $storage           Protected photo filesystem.
	 * @param EventRepository  $events            Audit log.
	 * @param EventDispatcher  $dispatcher        In-process dispatch.
	 * @param Clock            $clock             Source of now.
	 * @param callable(): int  $retention_months  Returns configured months.
	 */
	public function __construct(
		MediaRepository $media,
		PhotoStorage $storage,
		EventRepository $events,
		EventDispatcher $dispatcher,
		Clock $clock,
		callable $retention_months
	) {
		$this->media            = $media;
		$this->storage          = $storage;
		$this->events           = $events;
		$this->dispatcher       = $dispatcher;
		$this->clock            = $clock;
		$this->retention_months = $retention_months;
	}

	/**
	 * Purges up to `$limit` eligible photos.
	 *
	 * @param int $limit Max candidates this run (bounded).
	 */
	public function purge_batch( int $limit = self::DEFAULT_BATCH_SIZE ): PhotoRetentionResult {
		$limit = max( 1, min( 500, $limit ) );
		$months = (int) ( $this->retention_months )();
		$now    = $this->clock->now();
		$cutoff = PhotoRetentionEligibility::cutoff( $months, $now );

		if ( null === $cutoff ) {
			return PhotoRetentionResult::empty();
		}

		$candidates = $this->media->list_purge_candidates( $cutoff, $limit );
		$examined   = 0;
		$purged     = 0;
		$skipped    = 0;
		$failed     = 0;
		$failures   = array();

		foreach ( $candidates as $photo ) {
			++$examined;

			if ( ! PhotoRetentionEligibility::is_eligible( $photo, $cutoff ) ) {
				++$skipped;
				continue;
			}

			try {
				$outcome = $this->purge_one( $photo, $now, $months );

				if ( 'purged' === $outcome ) {
					++$purged;
				} elseif ( 'skipped' === $outcome ) {
					++$skipped;
				} else {
					++$failed;
					$failures[] = 'photo_id=' . (int) $photo->id() . ' purge incomplete';
				}
			} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Logged via bounded failure list; continue batch.
				++$failed;
				$failures[] = 'photo_id=' . (int) $photo->id() . ' ' . self::bounded_message( $e->getMessage() );
			}
		}

		return new PhotoRetentionResult( $examined, $purged, $skipped, $failed, $failures );
	}

	/**
	 * Purges one photo. Returns purged|skipped|failed.
	 *
	 * @param PhotoRecord       $photo  Candidate.
	 * @param \DateTimeImmutable $now   Purge timestamp.
	 * @param int               $months Policy months for audit context.
	 */
	private function purge_one( PhotoRecord $photo, $now, int $months ): string {
		$fresh = $this->media->get( (int) $photo->id() );

		if ( null === $fresh || $fresh->is_purged() ) {
			return 'skipped';
		}

		$file_path         = $fresh->file_path();
		$thumb_path        = $fresh->thumb_path();
		$canonical_present = self::bytes_present( $this->storage, $file_path );
		$thumb_present     = self::bytes_present( $this->storage, $thumb_path );

		if ( '' !== $file_path ) {
			if ( ! $this->storage->belongs_to_photo_root( $file_path ) ) {
				return 'failed';
			}

			if ( ! $this->storage->delete_relative( $file_path ) ) {
				return 'failed';
			}
		}

		if ( '' !== $thumb_path ) {
			if ( ! $this->storage->belongs_to_photo_root( $thumb_path ) ) {
				return 'failed';
			}

			if ( ! $this->storage->delete_relative( $thumb_path ) ) {
				return 'failed';
			}
		}

		$canonical_removed = true;
		$thumb_removed     = true;

		// Idempotent recovery: missing files count as removed.
		if ( ! $this->media->mark_purged( (int) $fresh->id(), $now ) ) {
			// Race: another worker marked purged — treat as success.
			$again = $this->media->get( (int) $fresh->id() );

			if ( null !== $again && $again->is_purged() ) {
				return 'purged';
			}

			return 'failed';
		}

		$this->record_purged(
			$fresh,
			$now,
			$months,
			$canonical_present,
			$thumb_present,
			$canonical_removed,
			$thumb_removed
		);

		return 'purged';
	}

	/**
	 * Appends photo.purged (no filesystem paths in payload).
	 *
	 * @param PhotoRecord        $photo              Pre-purge metadata snapshot.
	 * @param \DateTimeImmutable $now                Purge time.
	 * @param int                $months             Policy months.
	 * @param bool               $canonical_present  Canonical existed before delete.
	 * @param bool               $thumb_present      Thumbnail existed before delete.
	 * @param bool               $canonical_removed  Canonical delete attempted/ok.
	 * @param bool               $thumb_removed      Thumbnail delete attempted/ok.
	 */
	private function record_purged(
		PhotoRecord $photo,
		$now,
		int $months,
		bool $canonical_present,
		bool $thumb_present,
		bool $canonical_removed,
		bool $thumb_removed
	): void {
		$payload = array(
			'photo_id'            => (int) $photo->id(),
			'package_id'          => $photo->package_id(),
			'kind'                => $photo->kind(),
			'sha256'              => $photo->sha256(),
			'processing_version'  => $photo->processing_version(),
			'bytes'               => $photo->bytes(),
			'retention_months'    => $months,
			'policy'              => 'age',
			'canonical_present'   => $canonical_present,
			'thumbnail_present'   => $thumb_present,
			'canonical_removed'   => $canonical_removed,
			'thumbnail_removed'   => $thumb_removed,
		);

		$event     = DomainEvent::for_fulfillment(
			$photo->fulfillment_id(),
			'photo.purged',
			Actor::system( 'photo-retention' ),
			$now,
			$payload
		);
		$prev_hash = $this->events->last_hash_for_fulfillment( $photo->fulfillment_id() );

		$this->events->append( $event, $prev_hash );
		$this->dispatcher->dispatch( $event );
	}

	/**
	 * Whether relative bytes currently exist under the protected store.
	 *
	 * @param PhotoStorage $storage  Photo store.
	 * @param string       $relative Relative path (may be empty after a prior attempt).
	 */
	private static function bytes_present( PhotoStorage $storage, string $relative ): bool {
		return '' !== $relative && $storage->exists_relative( $relative );
	}

	/**
	 * Strips path-like fragments from failure messages.
	 *
	 * @param string $message Raw exception message.
	 */
	private static function bounded_message( string $message ): string {
		$clean = preg_replace( '#(/[^\s]+)+#', '[path]', $message );

		return substr( is_string( $clean ) ? $clean : 'error', 0, 160 );
	}
}
