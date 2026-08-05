<?php
/**
 * Tests for the document assembly/render/record orchestrator.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application;

use DateTimeImmutable;
use MPCF\Application\DocumentService;
use MPCF\Application\EventDispatcher;
use MPCF\Application\ShippingService;
use MPCF\Documents\HtmlRenderer;
use MPCF\Documents\TemplateRegistry;
use MPCF\Domain\Document\DocumentModel;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\OrderSnapshot;
use MPCF\Domain\Shipping\Package;
use MPCF\Domain\Shipping\PackageSpec;
use MPCF\Domain\Shipping\Shipment;
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
 * Tests for the document assembly/render/record orchestrator.
 */
final class DocumentServiceTest extends TestCase {

	/**
	 * @var InMemoryFulfillmentRepository
	 */
	private InMemoryFulfillmentRepository $fulfillments;

	/**
	 * @var InMemoryFulfillmentItemRepository
	 */
	private InMemoryFulfillmentItemRepository $items;

	/**
	 * @var InMemoryOrderSource
	 */
	private InMemoryOrderSource $orders;

	/**
	 * @var InMemoryShipmentRepository
	 */
	private InMemoryShipmentRepository $shipments;

	/**
	 * @var InMemoryPackageRepository
	 */
	private InMemoryPackageRepository $packages;

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
	private DocumentService $service;

	/**
	 * @var int
	 */
	private int $fulfillment_id;

	protected function setUp(): void {
		remove_all_filters( 'mpcf_document_model' );
		remove_all_filters( 'mpcf_document_types' );
		remove_all_filters( 'mpcf_document_template' );

		$this->fulfillments = new InMemoryFulfillmentRepository();
		$this->items        = new InMemoryFulfillmentItemRepository();
		$this->orders       = new InMemoryOrderSource();
		$this->shipments    = new InMemoryShipmentRepository();
		$this->packages     = new InMemoryPackageRepository();
		$this->documents    = new InMemoryDocumentRepository();
		$this->events       = new InMemoryEventRepository();

		$shipping = new ShippingService(
			$this->fulfillments,
			$this->items,
			$this->shipments,
			$this->packages,
			new InMemoryPackageItemRepository(),
			$this->events,
			new EventDispatcher(),
			new FixedClock( new DateTimeImmutable( '2026-08-02 10:00:00' ) )
		);

		$this->service = new DocumentService(
			$this->fulfillments,
			$this->items,
			$this->orders,
			$shipping,
			new HtmlRenderer( new TemplateRegistry() ),
			$this->documents,
			$this->events,
			new EventDispatcher(),
			new FixedClock( new DateTimeImmutable( '2026-08-02 10:00:00' ) ),
			'Acme Store'
		);

		$this->fulfillment_id = $this->fulfillments->insert(
			Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'packed', '#1001', 'Jane Doe', 1, new DateTimeImmutable() )
		);

		$this->items->insert_all( array( FulfillmentItem::intake( $this->fulfillment_id, 501, 900, 0, 'SKU-1', 'Blue Widget', 2 ) ) );

		$this->orders->seed(
			OrderSnapshot::create( 1001, 'woocommerce', '#1001', 'Jane Doe', 'processing', array(), array( 'Anna Andersson', 'Storgatan 1' ) )
		);

		$shipment_id = $this->shipments->insert( Shipment::create( $this->fulfillment_id, new DateTimeImmutable() ) );
		$package     = Package::create( $shipment_id, 1, new DateTimeImmutable() );
		$package->set_spec( PackageSpec::create( 500, null, null, null ) );
		$this->packages->insert( $package );
	}

	protected function tearDown(): void {
		remove_all_filters( 'mpcf_document_model' );
		remove_all_filters( 'mpcf_document_types' );
		remove_all_filters( 'mpcf_document_template' );
		parent::tearDown();
	}

	public function test_render_packing_slip_wrapper_delegates_to_render(): void {
		$via_wrapper = $this->service->render_packing_slip( $this->fulfillment_id, Actor::user( 7, 'Jane' ) );
		$via_render  = $this->service->render( $this->fulfillment_id, 'packing_slip', array( 'actor' => Actor::user( 8, 'Bob' ) ) );

		self::assertTrue( $via_wrapper->is_success() );
		self::assertTrue( $via_render->is_success() );
		self::assertStringContainsString( '#1001', (string) $via_wrapper->html() );
		self::assertStringContainsString( '#1001', (string) $via_render->html() );
		self::assertCount( 2, $this->documents->all() );
	}

	public function test_render_packing_slip_produces_html_containing_the_assembled_data(): void {
		$outcome = $this->service->render_packing_slip( $this->fulfillment_id, Actor::user( 7, 'Jane' ) );

		self::assertTrue( $outcome->is_success() );
		self::assertStringContainsString( '#1001', (string) $outcome->html() );
		self::assertStringContainsString( 'Anna Andersson', (string) $outcome->html() );
		self::assertStringContainsString( 'Blue Widget', (string) $outcome->html() );
		self::assertStringContainsString( 'Acme Store', (string) $outcome->html() );
	}

	public function test_render_packing_slip_records_exactly_one_document_and_one_audit_event(): void {
		$outcome = $this->service->render_packing_slip( $this->fulfillment_id, Actor::user( 7, 'Jane' ) );

		self::assertCount( 1, $this->documents->all() );
		self::assertSame( $outcome->document_id(), $this->documents->all()[0]->id() );
		self::assertNull( $this->documents->all()[0]->file_path(), 'Render-to-print never stores a file.' );

		$timeline = $this->events->timeline_for_fulfillment( $this->fulfillment_id );
		self::assertCount( 1, $timeline );
		self::assertSame( 'document.rendered', $timeline[0]['event_type'] );
		self::assertSame( $outcome->document_id(), $timeline[0]['payload']['document_id'] );
		self::assertSame( 'html', $timeline[0]['payload']['renderer'] );
	}

	public function test_render_rejects_unknown_document_type(): void {
		$outcome = $this->service->render( $this->fulfillment_id, 'delivery_note', array( 'actor' => Actor::system() ) );

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'unknown_document_type', $outcome->failure_code() );
		self::assertCount( 0, $this->documents->all() );
	}

	public function test_render_picking_list_is_registered_but_not_implemented_in_m4a(): void {
		$picking_id = $this->fulfillments->insert(
			Fulfillment::intake( 2002, 'woocommerce', 1, 'standard', 'picking', '#2002', 'A', 1, new DateTimeImmutable() )
		);
		$this->orders->seed(
			OrderSnapshot::create( 2002, 'woocommerce', '#2002', 'A', 'processing', array(), array( 'Line' ) )
		);

		$outcome = $this->service->render( $picking_id, 'picking_list', array( 'actor' => Actor::system() ) );

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'not_implemented', $outcome->failure_code() );
	}

	public function test_render_rejects_cancelled_stage(): void {
		$cancelled_id = $this->fulfillments->insert(
			Fulfillment::intake( 3003, 'woocommerce', 1, 'standard', 'cancelled', '#3003', 'A', 1, new DateTimeImmutable() )
		);
		$this->orders->seed(
			OrderSnapshot::create( 3003, 'woocommerce', '#3003', 'A', 'cancelled', array(), array( 'Line' ) )
		);

		$outcome = $this->service->render_packing_slip( $cancelled_id, Actor::system() );

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'stage_not_allowed', $outcome->failure_code() );
	}

	public function test_render_packing_slip_fails_for_an_unknown_fulfillment(): void {
		$outcome = $this->service->render_packing_slip( 999999, Actor::system() );

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'not_found', $outcome->failure_code() );
	}

	public function test_render_packing_slip_fails_when_the_owning_order_no_longer_exists(): void {
		$orphan_id = $this->fulfillments->insert(
			Fulfillment::intake( 9999, 'woocommerce', 1, 'standard', 'packed', '#9999', 'Nobody', 0, new DateTimeImmutable() )
		);

		$outcome = $this->service->render_packing_slip( $orphan_id, Actor::system() );

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'not_found', $outcome->failure_code() );
		self::assertCount( 0, $this->documents->all() );
	}

	public function test_model_filter_must_return_document_model(): void {
		add_filter(
			'mpcf_document_model',
			static function () {
				return 'not-a-model';
			}
		);

		$outcome = $this->service->render_packing_slip( $this->fulfillment_id, Actor::system() );

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'invalid_payload', $outcome->failure_code() );
	}

	public function test_model_filter_may_amend_fields_without_changing_doc_type(): void {
		add_filter(
			'mpcf_document_model',
			static function ( DocumentModel $model ): DocumentModel {
				return new DocumentModel(
					$model->doc_type(),
					$model->fulfillment_id(),
					$model->order_number(),
					$model->customer_name(),
					$model->ship_to_lines(),
					'Filtered Store',
					$model->items(),
					$model->packages(),
					$model->barcode_payload(),
					$model->fulfillment_state(),
					$model->template_version(),
					$model->branding(),
					$model->rendered_at(),
					$model->rendered_by()
				);
			}
		);

		$outcome = $this->service->render_packing_slip( $this->fulfillment_id, Actor::system() );

		self::assertTrue( $outcome->is_success() );
		self::assertStringContainsString( 'Filtered Store', (string) $outcome->html() );
	}

	public function test_repository_reads_after_render(): void {
		$outcome = $this->service->render_packing_slip( $this->fulfillment_id, Actor::user( 7, 'Jane' ) );

		self::assertNotNull( $this->documents->get( (int) $outcome->document_id() ) );
		self::assertCount( 1, $this->documents->list_for_fulfillment( $this->fulfillment_id ) );
		$latest = $this->documents->latest_for_fulfillment_and_type( $this->fulfillment_id, 'packing_slip' );
		self::assertNotNull( $latest );
		self::assertSame( $outcome->document_id(), $latest->id() );
	}

	public function test_no_file_path_is_written_in_m4a(): void {
		$this->service->render_packing_slip( $this->fulfillment_id, Actor::system() );

		self::assertNull( $this->documents->all()[0]->file_path() );
	}
}
