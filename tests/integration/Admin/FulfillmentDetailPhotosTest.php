<?php
/**
 * Fulfillment Detail CS package photo gallery (M6-D).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Admin;

use DateTimeImmutable;
use DateTimeZone;
use MPCF\Admin\FulfillmentDetailPage;
use MPCF\Application\EventDispatcher;
use MPCF\Application\FulfillmentDetailService;
use MPCF\Application\NoteService;
use MPCF\Application\TransitionContextFactory;
use MPCF\Application\WorkflowService;
use MPCF\Capabilities;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Media\PhotoKind;
use MPCF\Domain\Media\PhotoRecord;
use MPCF\Domain\Shipping\Package;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Engine\GuardRegistry;
use MPCF\Engine\WorkflowEngine;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\Database\WpdbMediaRepository;
use MPCF\Infrastructure\Database\WpdbNoteRepository;
use MPCF\Infrastructure\Database\WpdbPackageRepository;
use MPCF\Infrastructure\Database\WpdbShipmentRepository;
use MPCF\Infrastructure\SystemClock;
use MPCF\Settings;
use MPCF\Vendor\Mpds\ComponentRenderer;
use MPCF\Vendor\Mpds\PageShell\AdminPageShell;
use MPCF\Vendor\Mpds\PageShell\SectionNavigation;
use WP_UnitTestCase;

/**
 * CS gallery: active photos grouped by package; soft-deleted hidden; purged metadata shown.
 */
final class FulfillmentDetailPhotosTest extends WP_UnitTestCase {

	/**
	 * @var WpdbFulfillmentRepository
	 */
	private WpdbFulfillmentRepository $fulfillments;

	/**
	 * @var WpdbFulfillmentItemRepository
	 */
	private WpdbFulfillmentItemRepository $items;

	/**
	 * @var WpdbMediaRepository
	 */
	private WpdbMediaRepository $media;

	/**
	 * @var WpdbShipmentRepository
	 */
	private WpdbShipmentRepository $shipments;

	/**
	 * @var WpdbPackageRepository
	 */
	private WpdbPackageRepository $packages;

	public function set_up(): void {
		parent::set_up();
		$this->fulfillments = new WpdbFulfillmentRepository();
		$this->items        = new WpdbFulfillmentItemRepository();
		$this->media        = new WpdbMediaRepository();
		$this->shipments    = new WpdbShipmentRepository();
		$this->packages     = new WpdbPackageRepository();
	}

	public function test_detail_gallery_shows_active_hides_deleted_and_labels_purged(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$now = new DateTimeImmutable( '2026-08-06 12:00:00', new DateTimeZone( 'UTC' ) );
		$fid = $this->fulfillments->insert(
			Fulfillment::intake( 91001, 'woocommerce', 1, 'standard', 'packed', '#91001', 'CS', 1, $now )
		);
		$this->items->insert_all( array( FulfillmentItem::intake( $fid, 1, 1, 0, 'SKU', 'Item', 1 ) ) );
		$sid = $this->shipments->insert( Shipment::create( $fid, $now ) );
		$pid = $this->packages->insert( Package::create( $sid, 1, $now ) );

		$active_id = $this->media->insert(
			PhotoRecord::from_array(
				array(
					'fulfillment_id'     => $fid,
					'package_id'         => $pid,
					'kind'               => PhotoKind::PACKAGE,
					'file_path'          => 'mpcf/photos/2026/08/' . $fid . '/active.jpg',
					'thumb_path'         => 'mpcf/photos/2026/08/' . $fid . '/active-thumb.jpg',
					'mime'               => 'image/jpeg',
					'bytes'              => 100,
					'sha256'             => str_repeat( '11', 32 ),
					'processing_version' => 1,
					'width'              => 10,
					'height'             => 10,
					'seq'                => 1,
					'captured_by'        => 1,
					'created_at'         => $now,
					'deleted_at'         => null,
					'purged_at'          => null,
				)
			)
		);

		$deleted_id = $this->media->insert(
			PhotoRecord::from_array(
				array(
					'fulfillment_id'     => $fid,
					'package_id'         => $pid,
					'kind'               => PhotoKind::CONTENTS,
					'file_path'          => 'mpcf/photos/2026/08/' . $fid . '/deleted.jpg',
					'thumb_path'         => 'mpcf/photos/2026/08/' . $fid . '/deleted-thumb.jpg',
					'mime'               => 'image/jpeg',
					'bytes'              => 100,
					'sha256'             => str_repeat( '22', 32 ),
					'processing_version' => 1,
					'width'              => 10,
					'height'             => 10,
					'seq'                => 2,
					'captured_by'        => 1,
					'created_at'         => $now,
					'deleted_at'         => null,
					'purged_at'          => null,
				)
			)
		);
		$this->media->soft_delete( $deleted_id, $now );

		$purged_id = $this->media->insert(
			PhotoRecord::from_array(
				array(
					'fulfillment_id'     => $fid,
					'package_id'         => $pid,
					'kind'               => PhotoKind::CONTENTS,
					'file_path'          => 'mpcf/photos/2026/08/' . $fid . '/purged.jpg',
					'thumb_path'         => 'mpcf/photos/2026/08/' . $fid . '/purged-thumb.jpg',
					'mime'               => 'image/jpeg',
					'bytes'              => 100,
					'sha256'             => str_repeat( '33', 32 ),
					'processing_version' => 1,
					'width'              => 10,
					'height'             => 10,
					'seq'                => 3,
					'captured_by'        => 1,
					'created_at'         => $now,
					'deleted_at'         => null,
					'purged_at'          => null,
				)
			)
		);
		$this->media->mark_purged( $purged_id, $now );

		$_GET['page']           = FulfillmentDetailPage::SLUG;
		$_GET['fulfillment_id'] = (string) $fid;

		$events     = new WpdbEventRepository();
		$definition = StandardWorkflow::definition();
		$page       = new FulfillmentDetailPage(
			new AdminPageShell( new SectionNavigation() ),
			new ComponentRenderer(),
			new FulfillmentDetailService(
				$this->fulfillments,
				$this->items,
				$events,
				new WpdbNoteRepository(),
				$this->media,
				$this->shipments,
				$this->packages
			),
			new NoteService( new WpdbNoteRepository(), new SystemClock() ),
			new WorkflowService(
				$this->fulfillments,
				$events,
				new WorkflowEngine( GuardRegistry::standard() ),
				new EventDispatcher(),
				new SystemClock(),
				array( StandardWorkflow::NAME => $definition ),
				new TransitionContextFactory( $this->items, $this->shipments, $this->packages, new Settings( array() ) )
			),
			$definition
		);

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-mpcf-detail-photos', $html );
		self::assertStringContainsString( 'data-mpcf-photo-id="' . $active_id . '"', $html );
		self::assertStringNotContainsString( 'data-mpcf-photo-id="' . $deleted_id . '"', $html );
		self::assertStringContainsString( 'data-mpcf-photo-id="' . $purged_id . '"', $html );
		self::assertStringContainsString( 'Photo retained as audit metadata; image removed by retention policy.', $html );
		self::assertStringNotContainsString( 'mpcf/photos/', $html );
		self::assertStringNotContainsString( str_repeat( '33', 32 ), $html );
	}
}
