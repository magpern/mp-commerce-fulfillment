<?php
/**
 * Orchestrates package photography capture, soft-delete, and audit.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Photos;

use InvalidArgumentException;
use MPCF\Application\EventDispatcher;
use MPCF\Domain\Clock;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\Media\ImageProcessor;
use MPCF\Domain\Media\PhotoKind;
use MPCF\Domain\Media\PhotoRecord;
use MPCF\Domain\Media\PhotoStorage;
use MPCF\Domain\Repository\EventRepository;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Domain\Repository\MediaRepository;
use MPCF\Domain\Repository\PackageRepository;
use MPCF\Domain\Repository\ShipmentRepository;
use RuntimeException;
use Throwable;

/**
 * Part VIII sole mutation entry point for package photos. Admin/REST-free —
 * controllers (M6-B) enforce capabilities at the boundary.
 */
final class PhotoService {

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
	 * Image processing pipeline.
	 *
	 * @var ImageProcessor
	 */
	private ImageProcessor $processor;

	/**
	 * Fulfillment lookup.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

	/**
	 * Package lookup.
	 *
	 * @var PackageRepository
	 */
	private PackageRepository $packages;

	/**
	 * Shipment lookup (ownership chain).
	 *
	 * @var ShipmentRepository
	 */
	private ShipmentRepository $shipments;

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
	 * Capture limits and pipeline version.
	 *
	 * @var PhotoConfig
	 */
	private PhotoConfig $config;

	/**
	 * Builds the service.
	 *
	 * @param MediaRepository       $media         Photo persistence.
	 * @param PhotoStorage          $storage       Protected photo filesystem.
	 * @param ImageProcessor        $processor     Image processing pipeline.
	 * @param FulfillmentRepository $fulfillments  Fulfillment lookup.
	 * @param PackageRepository     $packages      Package lookup.
	 * @param ShipmentRepository    $shipments     Shipment lookup.
	 * @param EventRepository       $events        Audit log persistence.
	 * @param EventDispatcher       $dispatcher    In-process event dispatch.
	 * @param Clock                 $clock         Source of "now".
	 * @param PhotoConfig|null      $config        Capture limits.
	 */
	public function __construct(
		MediaRepository $media,
		PhotoStorage $storage,
		ImageProcessor $processor,
		FulfillmentRepository $fulfillments,
		PackageRepository $packages,
		ShipmentRepository $shipments,
		EventRepository $events,
		EventDispatcher $dispatcher,
		Clock $clock,
		?PhotoConfig $config = null
	) {
		$this->media        = $media;
		$this->storage      = $storage;
		$this->processor    = $processor;
		$this->fulfillments = $fulfillments;
		$this->packages     = $packages;
		$this->shipments    = $shipments;
		$this->events       = $events;
		$this->dispatcher   = $dispatcher;
		$this->clock        = $clock;
		$this->config       = $config ?? PhotoConfig::defaults();
	}

	/**
	 * Captures and stores a package photo for a fulfillment/package pair.
	 *
	 * @param int    $fulfillment_id Owning fulfillment.
	 * @param int    $package_id     Owning package (must belong to fulfillment).
	 * @param string $kind           Allow-listed kind.
	 * @param string $source_bytes   Raw upload bytes.
	 * @param string $declared_mime  Client-declared MIME.
	 * @param Actor  $actor          Who captured the photo.
	 * @throws InvalidArgumentException On validation failure.
	 * @throws RuntimeException         On persistence failure.
	 */
	public function capture(
		int $fulfillment_id,
		int $package_id,
		string $kind,
		string $source_bytes,
		string $declared_mime,
		Actor $actor
	): PhotoRecord {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			throw new InvalidArgumentException( 'Fulfillment not found.' );
		}

		$package = $this->packages->find( $package_id );

		if ( null === $package ) {
			throw new InvalidArgumentException( 'Package not found.' );
		}

		$shipment = $this->shipments->find( $package->shipment_id() );

		if ( null === $shipment || $shipment->fulfillment_id() !== $fulfillment_id ) {
			throw new InvalidArgumentException( 'Package does not belong to the fulfillment.' );
		}

		PhotoKind::assert_valid( $kind );

		if ( $this->media->count_active_for_fulfillment( $fulfillment_id ) >= $this->config->max_photos_per_fulfillment() ) {
			throw new InvalidArgumentException( 'Maximum active photos for this fulfillment has been reached.' );
		}

		if ( strlen( $source_bytes ) > $this->config->max_upload_bytes() ) {
			throw new InvalidArgumentException( 'Upload exceeds the maximum allowed size.' );
		}

		$processed = $this->processor->process( $source_bytes, $declared_mime );
		$now       = $this->clock->now();
		$stored    = $this->storage->write_pair(
			$fulfillment_id,
			$processed->canonical_bytes(),
			$processed->thumb_bytes(),
			'jpg',
			$now
		);

		$seq    = $this->media->next_sequence( $fulfillment_id );
		$record = PhotoRecord::create(
			$fulfillment_id,
			$package_id,
			$kind,
			$stored->relative_path(),
			$stored->thumb_relative_path(),
			$processed->mime(),
			$processed->bytes(),
			$processed->sha256(),
			$processed->processing_version(),
			$processed->width(),
			$processed->height(),
			$seq,
			$actor->id(),
			$now
		);

		try {
			$photo_id = $this->media->insert( $record );
		} catch ( Throwable $e ) {
			$this->storage->delete_relative( $stored->relative_path() );
			$this->storage->delete_relative( $stored->thumb_relative_path() );
			throw new RuntimeException( 'Unable to record the photo: ' . $e->getMessage(), 0, $e );
		}

		if ( $photo_id <= 0 ) {
			$this->storage->delete_relative( $stored->relative_path() );
			$this->storage->delete_relative( $stored->thumb_relative_path() );
			throw new RuntimeException( 'Unable to record the photo.' );
		}

		$this->record_event(
			$fulfillment_id,
			'photo.captured',
			$actor,
			$now,
			array(
				'photo_id'           => $photo_id,
				'fulfillment_id'     => $fulfillment_id,
				'package_id'         => $package_id,
				'kind'               => $kind,
				'sha256'             => $processed->sha256(),
				'processing_version' => $processed->processing_version(),
				'bytes'              => $processed->bytes(),
				'mime'               => $processed->mime(),
				'width'              => $processed->width(),
				'height'             => $processed->height(),
			)
		);

		$saved = $this->media->get( $photo_id );

		if ( null === $saved ) {
			throw new RuntimeException( 'Photo was recorded but could not be reloaded.' );
		}

		return $saved;
	}

	/**
	 * Captures a photo and bumps the fulfillment optimistic-lock version.
	 *
	 * Checks `$expected_version` before expensive processing. After a
	 * successful insert, calls {@see FulfillmentRepository::touch()}; if
	 * touch loses a race the photo remains (operational evidence) and a
	 * {@see RuntimeException} with message `version_conflict` is thrown.
	 *
	 * @param int    $fulfillment_id   Owning fulfillment.
	 * @param int    $package_id       Owning package.
	 * @param string $kind             Allow-listed kind.
	 * @param string $source_bytes     Raw upload bytes.
	 * @param string $declared_mime    Client-declared MIME.
	 * @param Actor  $actor            Who captured the photo.
	 * @param int    $expected_version Caller's fulfillment version.
	 * @throws InvalidArgumentException On validation failure or version mismatch.
	 * @throws RuntimeException         On persistence failure or post-insert version race.
	 */
	public function capture_with_version(
		int $fulfillment_id,
		int $package_id,
		string $kind,
		string $source_bytes,
		string $declared_mime,
		Actor $actor,
		int $expected_version
	): PhotoMutationResult {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			throw new InvalidArgumentException( 'Fulfillment not found.' );
		}

		if ( $fulfillment->version() !== $expected_version ) {
			throw new InvalidArgumentException( 'version_conflict' );
		}

		$photo = $this->capture( $fulfillment_id, $package_id, $kind, $source_bytes, $declared_mime, $actor );

		if ( ! $this->fulfillments->touch( $fulfillment_id, $expected_version ) ) {
			throw new RuntimeException( 'version_conflict' );
		}

		return new PhotoMutationResult(
			$photo,
			$expected_version + 1,
			$this->requirement_satisfied( $fulfillment_id )
		);
	}

	/**
	 * Loads one photo by id.
	 *
	 * @param int $photo_id Photo id.
	 */
	public function get( int $photo_id ): ?PhotoRecord {
		return $this->media->get( $photo_id );
	}

	/**
	 * Loads an active (non-deleted) photo, or null for missing/deleted
	 * (404 metadata policy).
	 *
	 * @param int $photo_id Photo id.
	 */
	public function get_active( int $photo_id ): ?PhotoRecord {
		$photo = $this->media->get( $photo_id );

		if ( null === $photo || $photo->is_deleted() ) {
			return null;
		}

		return $photo;
	}

	/**
	 * Lists photos for a fulfillment.
	 *
	 * @param int  $fulfillment_id  Fulfillment id.
	 * @param bool $include_deleted Whether to include soft-deleted rows.
	 * @return list<PhotoRecord>
	 */
	public function list_for_fulfillment( int $fulfillment_id, bool $include_deleted = false ): array {
		return $this->media->list_for_fulfillment( $fulfillment_id, $include_deleted );
	}

	/**
	 * Lists active photos for a fulfillment, optionally filtered.
	 *
	 * @param int         $fulfillment_id Fulfillment id.
	 * @param int|null    $package_id     Optional package filter.
	 * @param string|null $kind           Optional kind filter.
	 * @return list<PhotoRecord>
	 * @throws InvalidArgumentException When `$kind` is not allow-listed.
	 */
	public function list_active( int $fulfillment_id, ?int $package_id = null, ?string $kind = null ): array {
		if ( null !== $kind ) {
			PhotoKind::assert_valid( $kind );
		}

		$photos = $this->media->list_for_fulfillment( $fulfillment_id, false );
		$out    = array();

		foreach ( $photos as $photo ) {
			if ( null !== $package_id && $photo->package_id() !== $package_id ) {
				continue;
			}

			if ( null !== $kind && $photo->kind() !== $kind ) {
				continue;
			}

			$out[] = $photo;
		}

		usort(
			$out,
			static function ( PhotoRecord $a, PhotoRecord $b ): int {
				$by_package = $a->package_id() <=> $b->package_id();

				return 0 !== $by_package ? $by_package : ( $a->seq() <=> $b->seq() );
			}
		);

		return $out;
	}

	/**
	 * Lists photos for a package.
	 *
	 * @param int  $package_id      Package id.
	 * @param bool $include_deleted Whether to include soft-deleted rows.
	 * @return list<PhotoRecord>
	 */
	public function list_for_package( int $package_id, bool $include_deleted = false ): array {
		return $this->media->list_for_package( $package_id, $include_deleted );
	}

	/**
	 * Soft-deletes a photo (idempotent). Capability checks are controller-boundary (M6-B).
	 *
	 * @param int   $photo_id Photo id.
	 * @param Actor $actor    Who deleted the photo.
	 * @throws InvalidArgumentException When the photo is missing.
	 * @throws RuntimeException         When the photo cannot be reloaded after delete.
	 */
	public function soft_delete( int $photo_id, Actor $actor ): PhotoRecord {
		$photo = $this->media->get( $photo_id );

		if ( null === $photo ) {
			throw new InvalidArgumentException( 'Photo not found.' );
		}

		if ( $photo->is_deleted() ) {
			return $photo;
		}

		$now = $this->clock->now();
		$this->media->soft_delete( $photo_id, $now );

		$this->record_event(
			$photo->fulfillment_id(),
			'photo.deleted',
			$actor,
			$now,
			array(
				'photo_id'           => $photo_id,
				'package_id'         => $photo->package_id(),
				'sha256'             => $photo->sha256(),
				'processing_version' => $photo->processing_version(),
			)
		);

		$reloaded = $this->media->get( $photo_id );

		if ( null === $reloaded ) {
			throw new RuntimeException( 'Photo was deleted but could not be reloaded.' );
		}

		return $reloaded;
	}

	/**
	 * Soft-deletes a photo and bumps the fulfillment version.
	 *
	 * Already-deleted photos are idempotent: no new event, no touch, current
	 * fulfillment version returned.
	 *
	 * @param int   $photo_id          Photo id.
	 * @param Actor $actor             Who deleted the photo.
	 * @param int   $expected_version  Caller's fulfillment version.
	 * @throws InvalidArgumentException When missing or version mismatches.
	 * @throws RuntimeException         On post-delete version race.
	 */
	public function soft_delete_with_version( int $photo_id, Actor $actor, int $expected_version ): PhotoMutationResult {
		$photo = $this->media->get( $photo_id );

		if ( null === $photo ) {
			throw new InvalidArgumentException( 'Photo not found.' );
		}

		$fulfillment = $this->fulfillments->find( $photo->fulfillment_id() );

		if ( null === $fulfillment ) {
			throw new InvalidArgumentException( 'Fulfillment not found.' );
		}

		if ( $fulfillment->version() !== $expected_version ) {
			throw new InvalidArgumentException( 'version_conflict' );
		}

		if ( $photo->is_deleted() ) {
			return new PhotoMutationResult(
				$photo,
				$fulfillment->version(),
				$this->requirement_satisfied( $photo->fulfillment_id() )
			);
		}

		$deleted = $this->soft_delete( $photo_id, $actor );

		if ( ! $this->fulfillments->touch( $photo->fulfillment_id(), $expected_version ) ) {
			throw new RuntimeException( 'version_conflict' );
		}

		return new PhotoMutationResult(
			$deleted,
			$expected_version + 1,
			$this->requirement_satisfied( $photo->fulfillment_id() )
		);
	}

	/**
	 * Reads canonical or thumbnail bytes for streaming. Never returns paths.
	 *
	 * @param int    $photo_id Photo id.
	 * @param string $which    `content` or `thumb`.
	 * @return array{ok:bool,code?:string,message?:string,bytes?:string,mime?:string,filename?:string,photo?:PhotoRecord}
	 */
	public function read_bytes( int $photo_id, string $which ): array {
		$photo = $this->media->get( $photo_id );

		if ( null === $photo ) {
			return array(
				'ok'      => false,
				'code'    => 'photo_not_found',
				'message' => 'Photo not found.',
			);
		}

		if ( $photo->is_deleted() ) {
			return array(
				'ok'      => false,
				'code'    => 'photo_deleted',
				'message' => 'Photo has been deleted.',
			);
		}

		if ( $photo->is_purged() || ! $photo->has_bytes() ) {
			return array(
				'ok'      => false,
				'code'    => 'photo_purged',
				'message' => 'Photo bytes have been removed by retention policy.',
			);
		}

		$relative = 'thumb' === $which ? $photo->thumb_path() : $photo->file_path();
		$absolute = $this->storage->absolute_path( $relative );

		if ( null === $absolute || ! is_readable( $absolute ) ) {
			return array(
				'ok'      => false,
				'code'    => 'photo_content_missing',
				'message' => 'Photo file is missing from storage.',
			);
		}

		$bytes = file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Trusted path from PhotoStorage::absolute_path under the protected photo root.

		if ( false === $bytes ) {
			return array(
				'ok'      => false,
				'code'    => 'photo_content_missing',
				'message' => 'Photo file is missing from storage.',
			);
		}

		$is_thumb = 'thumb' === $which;

		return array(
			'ok'       => true,
			'bytes'    => $bytes,
			'mime'     => $is_thumb ? 'image/jpeg' : $photo->mime(),
			'filename' => $is_thumb
				? 'mpcf-photo-' . $photo_id . '-thumb.jpg'
				: 'mpcf-photo-' . $photo_id . '.jpg',
			'photo'    => $photo,
		);
	}

	/**
	 * Whether the fulfillment has at least one active package-kind photo.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function requirement_satisfied( int $fulfillment_id ): bool {
		return $this->media->count_active_package_photos( $fulfillment_id ) > 0;
	}

	/**
	 * Appends one hash-chained audit event and dispatches it.
	 *
	 * @param int                  $fulfillment_id Fulfillment the event belongs to.
	 * @param string               $event_type     Event type to record.
	 * @param Actor                $actor          Who caused this event.
	 * @param \DateTimeImmutable   $now            When this event occurred.
	 * @param array<string, mixed> $payload        Event payload.
	 */
	private function record_event( int $fulfillment_id, string $event_type, Actor $actor, $now, array $payload ): void {
		$event     = DomainEvent::for_fulfillment( $fulfillment_id, $event_type, $actor, $now, $payload );
		$prev_hash = $this->events->last_hash_for_fulfillment( $fulfillment_id );

		$this->events->append( $event, $prev_hash );
		$this->dispatcher->dispatch( $event );
	}
}
