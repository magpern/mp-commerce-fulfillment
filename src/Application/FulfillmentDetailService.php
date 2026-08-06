<?php
/**
 * Read-side facade for the Fulfillment Detail screen.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use MPCF\Domain\EventTimelinePage;
use MPCF\Domain\Repository\EventRepository;
use MPCF\Domain\Repository\FulfillmentItemRepository;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Domain\Repository\MediaRepository;
use MPCF\Domain\Repository\NoteRepository;
use MPCF\Domain\Repository\PackageRepository;
use MPCF\Domain\Repository\ShipmentRepository;

/**
 * Aggregates a fulfillment with its items, full audit timeline, notes and
 * package photography evidence — Admin never assembles this from individual
 * repositories itself (invariant I11, `AdminBoundaryGuardTest`).
 */
final class FulfillmentDetailService {

	/**
	 * Fulfillment lookup.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

	/**
	 * Line items.
	 *
	 * @var FulfillmentItemRepository
	 */
	private FulfillmentItemRepository $items;

	/**
	 * Audit timeline.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * Notes.
	 *
	 * @var NoteRepository
	 */
	private NoteRepository $notes;

	/**
	 * Package photography metadata (optional until wired).
	 *
	 * @var MediaRepository|null
	 */
	private ?MediaRepository $media;

	/**
	 * Shipments for package identity.
	 *
	 * @var ShipmentRepository|null
	 */
	private ?ShipmentRepository $shipments;

	/**
	 * Packages for sequence labels.
	 *
	 * @var PackageRepository|null
	 */
	private ?PackageRepository $packages;

	/**
	 * Builds the service.
	 *
	 * @param FulfillmentRepository     $fulfillments Fulfillment lookup.
	 * @param FulfillmentItemRepository $items        Line items.
	 * @param EventRepository           $events       Audit timeline.
	 * @param NoteRepository            $notes        Notes.
	 * @param MediaRepository|null      $media        Package photos (M6-D).
	 * @param ShipmentRepository|null   $shipments    Shipments (M6-D).
	 * @param PackageRepository|null    $packages     Packages (M6-D).
	 */
	public function __construct(
		FulfillmentRepository $fulfillments,
		FulfillmentItemRepository $items,
		EventRepository $events,
		NoteRepository $notes,
		?MediaRepository $media = null,
		?ShipmentRepository $shipments = null,
		?PackageRepository $packages = null
	) {
		$this->fulfillments = $fulfillments;
		$this->items        = $items;
		$this->events       = $events;
		$this->notes        = $notes;
		$this->media        = $media;
		$this->shipments    = $shipments;
		$this->packages     = $packages;
	}

	/**
	 * Assembles the full detail view for one fulfillment, or null if it
	 * does not exist.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function get( int $fulfillment_id ): ?FulfillmentDetailView {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return null;
		}

		return new FulfillmentDetailView(
			$fulfillment,
			$this->items->find_for_fulfillment( $fulfillment_id ),
			$this->events->timeline_for_fulfillment( $fulfillment_id ),
			$this->notes->find_for_fulfillment( $fulfillment_id ),
			$this->build_photo_evidence( $fulfillment_id )
		);
	}

	/**
	 * One page of a fulfillment's audit timeline — the Fulfillment Detail
	 * screen's paginated audit trail (Architecture Plan §IV.10, risk
	 * M2-R11).
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @param int $page           1-indexed page number.
	 * @param int $per_page       Rows per page.
	 */
	public function get_timeline_page( int $fulfillment_id, int $page, int $per_page ): EventTimelinePage {
		return $this->events->timeline_page_for_fulfillment( $fulfillment_id, $page, $per_page );
	}

	/**
	 * The `$limit` most recently appended events for a fulfillment — the
	 * Packing Workspace's "last five events" (Architecture Plan §IV.5.2).
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @param int $limit          Maximum rows to return.
	 * @return list<array<string, mixed>>
	 */
	public function get_recent_timeline( int $fulfillment_id, int $limit ): array {
		return $this->events->recent_for_fulfillment( $fulfillment_id, $limit );
	}

	/**
	 * Active (non soft-deleted) photos for CS gallery, with package seq.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @return list<PackagePhotoEvidence>
	 */
	private function build_photo_evidence( int $fulfillment_id ): array {
		if ( null === $this->media ) {
			return array();
		}

		$seq_by_package = $this->package_sequences( $fulfillment_id );
		$out            = array();

		foreach ( $this->media->list_for_fulfillment( $fulfillment_id, false ) as $photo ) {
			$package_id = $photo->package_id();
			$out[]      = new PackagePhotoEvidence(
				(int) $photo->id(),
				$package_id,
				$seq_by_package[ $package_id ] ?? 0,
				$photo->kind(),
				$photo->created_at(),
				$photo->captured_by(),
				$photo->is_purged(),
				$photo->has_bytes(),
				$photo->purged_at()
			);
		}

		return $out;
	}

	/**
	 * Package id => seq map for this fulfillment.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @return array<int, int>
	 */
	private function package_sequences( int $fulfillment_id ): array {
		$map = array();

		if ( null === $this->shipments || null === $this->packages ) {
			return $map;
		}

		foreach ( $this->shipments->find_for_fulfillment( $fulfillment_id ) as $shipment ) {
			$shipment_id = (int) $shipment->id();

			foreach ( $this->packages->find_for_shipment( $shipment_id ) as $package ) {
				$map[ (int) $package->id() ] = $package->seq();
			}
		}

		return $map;
	}
}
