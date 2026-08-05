<?php
/**
 * Unit tests for document history, exact reprint, and capped bulk print.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application;

use DateTimeImmutable;
use MPCF\Application\DocumentHistoryService;
use MPCF\Application\DocumentService;
use MPCF\Application\EventDispatcher;
use MPCF\Application\ShippingService;
use MPCF\Documents\HtmlRenderer;
use MPCF\Documents\TemplateRegistry;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\OrderSnapshot;
use MPCF\Domain\Shipping\Package;
use MPCF\Domain\Shipping\PackageSpec;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Infrastructure\Files\ProtectedDocumentStore;
use MPCF\Settings;
use MPCF\Tests\Unit\Application\Doubles\FixedClock;
use MPCF\Tests\Unit\Application\Doubles\InMemoryDocumentRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryEventRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentItemRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryOrderSource;
use MPCF\Tests\Unit\Application\Doubles\InMemoryPackageItemRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryPackageRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryShipmentRepository;
use PHPUnit\Framework\TestCase;

/**
 * M4-D history / reprint / bulk behavior.
 */
final class DocumentHistoryServiceTest extends TestCase {

	/**
	 * @var InMemoryFulfillmentRepository
	 */
	private InMemoryFulfillmentRepository $fulfillments;

	/**
	 * @var InMemoryDocumentRepository
	 */
	private InMemoryDocumentRepository $documents;

	/**
	 * @var InMemoryEventRepository
	 */
	private InMemoryEventRepository $events;

	/**
	 * @var DocumentService
	 */
	private DocumentService $renderer;

	/**
	 * @var DocumentHistoryService
	 */
	private DocumentHistoryService $history;

	/**
	 * @var ProtectedDocumentStore
	 */
	private ProtectedDocumentStore $store;

	/**
	 * @var string
	 */
	private string $store_root;

	/**
	 * @var int
	 */
	private int $packed_id;

	/**
	 * @var int
	 */
	private int $queued_id;

	protected function setUp(): void {
		remove_all_filters( 'mpcf_document_model' );
		remove_all_filters( 'mpcf_document_types' );
		remove_all_filters( 'mpcf_document_template' );

		$this->fulfillments = new InMemoryFulfillmentRepository();
		$items              = new InMemoryFulfillmentItemRepository();
		$orders             = new InMemoryOrderSource();
		$shipments          = new InMemoryShipmentRepository();
		$packages           = new InMemoryPackageRepository();
		$this->documents    = new InMemoryDocumentRepository();
		$this->events       = new InMemoryEventRepository();
		$this->store_root   = sys_get_temp_dir() . '/mpcf-dochist-' . uniqid( '', true );
		$this->store        = new ProtectedDocumentStore( $this->store_root );
		$clock              = new FixedClock( new DateTimeImmutable( '2026-08-05 12:00:00' ) );
		$dispatcher         = new EventDispatcher();

		$shipping = new ShippingService(
			$this->fulfillments,
			$items,
			$shipments,
			$packages,
			new InMemoryPackageItemRepository(),
			$this->events,
			$dispatcher,
			$clock
		);

		$this->renderer = new DocumentService(
			$this->fulfillments,
			$items,
			$orders,
			$shipping,
			new HtmlRenderer( new TemplateRegistry() ),
			$this->documents,
			$this->events,
			$dispatcher,
			$clock,
			'Acme Store',
			null,
			new Settings(
				array(
					'documents_store_name' => 'Acme Store',
					'documents_address'    => "Warehouse 1\nStockholm",
					'documents_footer'     => 'Thank you',
				)
			),
			$this->store
		);

		$this->history = new DocumentHistoryService(
			$this->documents,
			$this->store,
			$this->events,
			$dispatcher,
			$clock,
			$this->renderer
		);

		$this->packed_id = $this->fulfillments->insert(
			Fulfillment::intake( 2001, 'woocommerce', 1, 'standard', 'packed', '#2001', 'Jane Doe', 1, new DateTimeImmutable() )
		);
		$items->insert_all( array( FulfillmentItem::intake( $this->packed_id, 501, 900, 0, 'SKU-1', 'Blue Widget', 2 ) ) );
		$orders->seed(
			OrderSnapshot::create( 2001, 'woocommerce', '#2001', 'Jane Doe', 'processing', array(), array( 'Anna Andersson', 'Storgatan 1' ), 'Leave at door' )
		);
		$shipment_id = $shipments->insert( Shipment::create( $this->packed_id, new DateTimeImmutable() ) );
		$package     = Package::create( $shipment_id, 1, new DateTimeImmutable() );
		$package->set_spec( PackageSpec::create( 500, null, null, null ) );
		$packages->insert( $package );

		$this->queued_id = $this->fulfillments->insert(
			Fulfillment::intake( 2002, 'woocommerce', 1, 'standard', 'queued', '#2002', 'Bob', 1, new DateTimeImmutable() )
		);
		$items->insert_all( array( FulfillmentItem::intake( $this->queued_id, 502, 901, 0, 'SKU-2', 'Red Widget', 1 ) ) );
		$orders->seed(
			OrderSnapshot::create( 2002, 'woocommerce', '#2002', 'Bob', 'processing', array(), array( 'Bob Buyer', 'Street 2' ), '' )
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'mpcf_document_model' );
		remove_all_filters( 'mpcf_document_types' );
		remove_all_filters( 'mpcf_document_template' );
		$this->rm_tree( $this->store_root );
		parent::tearDown();
	}

	public function test_search_lists_rendered_documents(): void {
		$this->renderer->render_packing_slip( $this->packed_id, Actor::user( 1, 'Op' ) );

		$result = $this->history->search( array( 'doc_type' => 'packing_slip' ) );

		self::assertSame( 1, $result['total'] );
		self::assertSame( 'packing_slip', $result['items'][0]['doc_type'] );
	}

	public function test_reprint_streams_exact_bytes_and_appends_reprinted_event_without_new_document(): void {
		$outcome = $this->renderer->render_packing_slip( $this->packed_id, Actor::user( 1, 'Op' ) );
		self::assertTrue( $outcome->is_success() );
		$document_id = (int) $outcome->document_id();
		$original    = (string) $outcome->html();
		$path        = (string) $this->documents->get( $document_id )->file_path();
		$absolute    = $this->store->absolute_path( $path );
		self::assertNotNull( $absolute );
		$before_hash = hash_file( 'sha256', $absolute );

		$reprint = $this->history->reprint( $document_id, Actor::user( 2, 'Lead' ) );

		self::assertTrue( $reprint['ok'] );
		self::assertSame( $original, $reprint['html'] );
		self::assertCount( 1, $this->documents->all(), 'Reprint must not create a new document row.' );
		self::assertSame( $before_hash, hash_file( 'sha256', (string) $absolute ), 'Stored artifact must remain unchanged.' );

		$types = array_column( $this->events->timeline_for_fulfillment( $this->packed_id ), 'event_type' );
		self::assertContains( 'document.rendered', $types );
		self::assertContains( 'document.reprinted', $types );

		$reprinted = null;
		foreach ( $this->events->timeline_for_fulfillment( $this->packed_id ) as $event ) {
			if ( 'document.reprinted' === $event['event_type'] ) {
				$reprinted = $event;
			}
		}
		self::assertNotNull( $reprinted );
		self::assertSame( $document_id, (int) $reprinted['payload']['source_document_id'] );
	}

	public function test_read_content_rejects_missing_artifact(): void {
		$outcome = $this->renderer->render_packing_slip( $this->packed_id, Actor::user( 1, 'Op' ) );
		$record  = $this->documents->get( (int) $outcome->document_id() );
		$path    = $this->store->absolute_path( (string) $record->file_path() );
		self::assertNotNull( $path );
		unlink( $path );

		$result = $this->history->read_content( (int) $outcome->document_id() );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'missing_artifact', $result['code'] );
	}

	public function test_absolute_path_rejects_traversal(): void {
		self::assertNull( $this->store->absolute_path( 'mpcf/documents/../../../etc/passwd' ) );
		self::assertNull( $this->store->absolute_path( 'wp-content/uploads/secret.html' ) );
	}

	public function test_bulk_picking_lists_cap_and_partial_failure(): void {
		// 28 unique ids → first 25 processed, last 3 skipped by cap.
		$ids = array_merge(
			array( $this->queued_id, $this->packed_id ),
			range( 900001, 900026 )
		);

		$bulk = $this->history->bulk_print_picking_lists(
			$ids,
			Actor::user( 1, 'Op' ),
			static fn( string $cap ): bool => true // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Capability gate signature.
		);

		self::assertArrayHasKey( $this->packed_id, $bulk['failed'], 'Packed stage cannot print picking list.' );
		self::assertCount( 3, $bulk['skipped_cap'] );
		self::assertSame( array( $this->queued_id ), $bulk['succeeded'] ? array_column( $bulk['succeeded'], 'fulfillment_id' ) : array() );
		self::assertNotSame( '', $bulk['combined_html'] );
		self::assertStringContainsString( 'page-break-after', $bulk['combined_html'] );

		$rendered = 0;
		foreach ( $this->events->timeline_for_fulfillment( $this->queued_id ) as $event ) {
			if ( 'document.rendered' === $event['event_type'] ) {
				++$rendered;
			}
		}
		self::assertSame( 1, $rendered );
	}

	public function test_no_delete_api_on_document_repository(): void {
		self::assertFalse( method_exists( $this->documents, 'delete' ) );
	}

	/**
	 * @param string $dir Directory tree.
	 */
	private function rm_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();
			if ( $file->isDir() ) {
				rmdir( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}
}
