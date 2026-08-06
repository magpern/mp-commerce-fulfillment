<?php
/**
 * Integration tests for the Queue admin screen's bulk actions, against a
 * real database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Admin;

use DateTimeImmutable;
use MPCF\Admin\QueuePage;
use MPCF\Application\AssignmentService;
use MPCF\Application\DocumentHistoryService;
use MPCF\Application\DocumentService;
use MPCF\Application\EventDispatcher;
use MPCF\Application\FulfillmentDetailService;
use MPCF\Application\QueueService;
use MPCF\Application\ShippingService;
use MPCF\Application\TransitionContextFactory;
use MPCF\Application\WorkflowService;
use MPCF\Capabilities;
use MPCF\Documents\HtmlRenderer;
use MPCF\Documents\TemplateRegistry;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Engine\GuardRegistry;
use MPCF\Engine\WorkflowEngine;
use MPCF\Infrastructure\Database\WpdbDocumentRepository;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\Database\WpdbNoteRepository;
use MPCF\Infrastructure\Database\WpdbPackageItemRepository;
use MPCF\Infrastructure\Database\WpdbPackageRepository;
use MPCF\Infrastructure\Database\WpdbSearchQuery;
use MPCF\Infrastructure\Database\WpdbShipmentRepository;
use MPCF\Infrastructure\Files\ProtectedDocumentStore;
use MPCF\Infrastructure\SystemClock;
use MPCF\Settings;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Woo\WooOrderSource;
use WP_UnitTestCase;

/**
 * `QueuePage::handle_bulk_action()` is exercised directly (no `$_POST`/
 * redirect simulation needed — see its own docblock) against a real
 * database and real capability checks.
 */
final class QueuePageTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;

	/**
	 * @var WpdbFulfillmentRepository
	 */
	private WpdbFulfillmentRepository $fulfillments;

	/**
	 * @var QueuePage
	 */
	private QueuePage $page;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();
		\MPCF\Plugin::activate();

		$this->fulfillments = new WpdbFulfillmentRepository();
		$items              = new WpdbFulfillmentItemRepository();
		$events             = new WpdbEventRepository();
		$shipments          = new WpdbShipmentRepository();
		$packages           = new WpdbPackageRepository();
		$clock              = new SystemClock();
		$dispatcher         = new EventDispatcher();
		$settings           = new Settings( array() );

		$definition = StandardWorkflow::definition();
		$workflow   = new WorkflowService(
			$this->fulfillments,
			$events,
			new WorkflowEngine( GuardRegistry::standard() ),
			$dispatcher,
			$clock,
			array( StandardWorkflow::NAME => $definition ),
			new TransitionContextFactory( $items, $shipments, $packages, $settings )
		);

		$shipping  = new ShippingService(
			$this->fulfillments,
			$items,
			$shipments,
			$packages,
			new WpdbPackageItemRepository(),
			$events,
			$dispatcher,
			$clock
		);
		$doc_repo  = new WpdbDocumentRepository();
		$doc_store = new ProtectedDocumentStore();
		$doc_svc   = new DocumentService(
			$this->fulfillments,
			$items,
			new WooOrderSource(),
			$shipping,
			new HtmlRenderer( new TemplateRegistry() ),
			$doc_repo,
			$events,
			$dispatcher,
			$clock,
			'Test Store',
			null,
			$settings,
			$doc_store
		);
		$history   = new DocumentHistoryService( $doc_repo, $doc_store, $events, $dispatcher, $clock, $doc_svc );

		$this->page = new QueuePage(
			new \MPCF\Vendor\Mpds\PageShell\AdminPageShell( new \MPCF\Vendor\Mpds\PageShell\SectionNavigation() ),
			new \MPCF\Vendor\Mpds\ComponentRenderer(),
			new QueueService( $this->fulfillments, new WpdbSearchQuery() ),
			new FulfillmentDetailService( $this->fulfillments, $items, $events, new WpdbNoteRepository() ),
			new AssignmentService( $this->fulfillments, $events, $dispatcher, $clock ),
			$workflow,
			$definition,
			$history
		);
	}

	private function seed( int $order_id ): int {
		$fulfillment = Fulfillment::intake( $order_id, 'woocommerce', 1, 'standard', 'queued', '#' . $order_id, 'Jane Doe', 1, new DateTimeImmutable() );
		$id          = $this->fulfillments->insert( $fulfillment );

		// An unpicked item is what makes AllItemsPickedGuard a real, non-
		// vacuous check for this fulfillment (an empty item list is
		// trivially "all picked").
		( new WpdbFulfillmentItemRepository() )->insert_all(
			array( \MPCF\Domain\FulfillmentItem::intake( $id, 501, 900, 0, 'SKU-1', 'Widget', 1 ) )
		);

		return $id;
	}

	public function test_render_links_each_row_directly_to_the_workspace_carrying_the_queue_cursor(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$id = $this->seed( 1001 );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-mpcf-row-open', $html );
		self::assertStringContainsString( 'page=mpcf-workspace', $html );
		self::assertStringContainsString( 'fulfillment_id=' . $id, $html );
		self::assertStringContainsString( 'cursor=' . $id, $html );
		self::assertStringContainsString( 'data-mpcf-drawer-open', $html );
	}

	public function test_render_drawer_offers_the_workspace_as_primary_and_detail_as_secondary(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$this->seed( 1002 );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Open in Workspace', $html );
		self::assertStringContainsString( 'Fulfillment Detail', $html );
		self::assertStringContainsString( 'page=mpcf-fulfillment-detail', $html );
	}

	public function test_bulk_assign_succeeds_for_valid_rows(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$id = $this->seed( 1001 );

		$result = $this->page->handle_bulk_action( array( $id ), 'assign', array( 'assignee_id' => 42 ) );

		self::assertSame( array( $id ), $result['succeeded'] );
		self::assertSame( array(), $result['failed'] );
		self::assertSame( 42, $this->fulfillments->find( $id )->assignee_id() );
	}

	public function test_bulk_action_reports_partial_failure_per_row(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$valid   = $this->seed( 2001 );
		$missing = 999999;

		$result = $this->page->handle_bulk_action( array( $valid, $missing ), 'assign', array( 'assignee_id' => 42 ) );

		self::assertSame( array( $valid ), $result['succeeded'] );
		self::assertArrayHasKey( $missing, $result['failed'] );
	}

	public function test_bulk_advance_is_guard_checked_and_rejects_an_ineligible_row(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		// Still queued: picking -> picked requires all_items_picked, but
		// this fulfillment has no items at all recorded as picked, so the
		// guard must reject it even though the lead has every capability.
		$id = $this->seed( 3001 );
		$this->page->handle_bulk_action( array( $id ), 'advance', array( 'target_state' => 'picking' ) );

		$result = $this->page->handle_bulk_action( array( $id ), 'advance', array( 'target_state' => 'picked' ) );

		self::assertSame( array(), $result['succeeded'] );
		self::assertArrayHasKey( $id, $result['failed'] );
	}

	public function test_bulk_advance_requiring_cancel_capability_is_rejected_for_an_operator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id = $this->seed( 4001 );

		$result = $this->page->handle_bulk_action( array( $id ), 'advance', array( 'target_state' => 'cancelled' ) );

		self::assertSame( array(), $result['succeeded'] );
		self::assertArrayHasKey( $id, $result['failed'] );
		self::assertSame( 'queued', $this->fulfillments->find( $id )->state(), 'An operator must never be able to cancel a fulfillment, even via bulk action.' );
	}

	public function test_bulk_advance_succeeds_for_a_lead_with_cancel_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$id = $this->seed( 5001 );

		$result = $this->page->handle_bulk_action( array( $id ), 'advance', array( 'target_state' => 'cancelled' ) );

		self::assertSame( array( $id ), $result['succeeded'] );
		self::assertSame( 'cancelled', $this->fulfillments->find( $id )->state() );
	}

	public function test_bulk_assign_is_rejected_without_the_process_capability(): void {
		// A subscriber has none of this plugin's capabilities at all.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$id = $this->seed( 6001 );

		$result = $this->page->handle_bulk_action( array( $id ), 'assign', array( 'assignee_id' => 42 ) );

		self::assertSame( array(), $result['succeeded'] );
		self::assertArrayHasKey( $id, $result['failed'] );
		self::assertNull( $this->fulfillments->find( $id )->assignee_id() );
	}
}
