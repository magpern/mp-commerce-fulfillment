<?php
/**
 * Integration tests for WorkspaceFlags, against a real database and
 * WooCommerce CRUD API.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Woo;

use DateTimeImmutable;
use MPCF\Admin\WorkspacePage;
use MPCF\Application\AssignmentService;
use MPCF\Application\EventDispatcher;
use MPCF\Application\FulfillmentDetailService;
use MPCF\Application\NoteService;
use MPCF\Application\ShippingService;
use MPCF\Application\TransitionContextFactory;
use MPCF\Application\WorkflowService;
use MPCF\Capabilities;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Engine\GuardRegistry;
use MPCF\Engine\WorkflowEngine;
use MPCF\Infrastructure\Carriers\BundledCarrierRegistry;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\Database\WpdbNoteRepository;
use MPCF\Infrastructure\Database\WpdbPackageItemRepository;
use MPCF\Infrastructure\Database\WpdbPackageRepository;
use MPCF\Infrastructure\Database\WpdbShipmentRepository;
use MPCF\Infrastructure\SystemClock;
use MPCF\Settings;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Vendor\Mpds\ComponentRenderer;
use MPCF\Vendor\Mpds\PageShell\AdminPageShell;
use MPCF\Vendor\Mpds\PageShell\SectionNavigation;
use MPCF\Woo\StoreUnits;
use MPCF\Woo\WorkspaceFlags;
use MPCF\Woo\WooOrderSource;
use WP_UnitTestCase;

/**
 * Regression tests for the WorkspaceFlags class, ensuring:
 * 1. The workspace page renders without fatal errors when loading real
 *    customer orders (regression for the undefined wc_get_customer_order_ids
 *    fatal).
 * 2. Repeat-customer detection works correctly when a customer has multiple
 *    orders in exception states.
 */
final class WorkspaceFlagsIntegrationTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;
	use OrderFactoryTrait;

	/**
	 * @var WpdbFulfillmentRepository
	 */
	private WpdbFulfillmentRepository $fulfillments;

	/**
	 * @var WpdbFulfillmentItemRepository
	 */
	private WpdbFulfillmentItemRepository $items;

	/**
	 * @var WpdbEventRepository
	 */
	private WpdbEventRepository $events;

	/**
	 * @var WorkspacePage
	 */
	private WorkspacePage $page;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();
		\MPCF\Plugin::activate();

		$this->fulfillments = new WpdbFulfillmentRepository();
		$this->items        = new WpdbFulfillmentItemRepository();
		$this->events       = new WpdbEventRepository();

		$definition = StandardWorkflow::definition();
		$dispatcher = new EventDispatcher();
		$clock      = new SystemClock();
		$shipments  = new WpdbShipmentRepository();
		$packages   = new WpdbPackageRepository();

		$workflow = new WorkflowService(
			$this->fulfillments,
			$this->events,
			new WorkflowEngine( GuardRegistry::standard() ),
			$dispatcher,
			$clock,
			array( StandardWorkflow::NAME => $definition ),
			new TransitionContextFactory( $this->items, $shipments, $packages, new Settings( array() ) )
		);

		$shipping = new ShippingService(
			$this->fulfillments,
			$this->items,
			$shipments,
			$packages,
			new WpdbPackageItemRepository(),
			$this->events,
			$dispatcher,
			$clock
		);

		$notes_repository = new WpdbNoteRepository();

		( new WorkspaceFlags( $this->fulfillments, $definition ) )->register();

		$this->page = new WorkspacePage(
			new AdminPageShell( new SectionNavigation() ),
			new ComponentRenderer(),
			new FulfillmentDetailService( $this->fulfillments, $this->items, $this->events, $notes_repository ),
			$workflow,
			$shipping,
			new NoteService( $notes_repository, $clock ),
			new BundledCarrierRegistry(),
			new AssignmentService( $this->fulfillments, $this->events, $dispatcher, $clock ),
			new WooOrderSource(),
			$definition,
			new StoreUnits(),
			new \MPCF\Infrastructure\Database\WpdbDocumentRepository(),
			new Settings( array() )
		);
	}

	/**
	 * Regression test: workspace page must render without fatal error when
	 * loading a fulfillment for a real customer order (not a guest checkout).
	 *
	 * This catches the v0.2.0 defect where WorkspaceFlags called the
	 * nonexistent wc_get_customer_order_ids() function.
	 */
	public function test_workspace_page_renders_without_fatal_for_customer_order(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$order       = $this->create_paid_order_for_customer( 2 );
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );

		self::assertNotNull( $fulfillment );
		self::assertGreaterThan( 0, $order->get_customer_id(), 'Test order must have a real customer ID' );

		$_GET['fulfillment_id'] = (string) $fulfillment->id();

		ob_start();
		try {
			$this->page->render();
			$html = (string) ob_get_clean();

			// If we reach here without a fatal, the test passes. Verify the
			// basic structure is present.
			self::assertStringContainsString( 'mpcf-ui-workspace-layout', $html );
			self::assertStringContainsString( 'Test Widget', $html );
		} catch ( \Throwable $e ) {
			ob_end_clean();
			self::fail( 'Workspace page render threw exception: ' . $e->getMessage() );
		}
	}

	/**
	 * Test repeat-customer flag: when a customer has another order in an
	 * exception state, the flag is rendered for subsequent orders.
	 */
	public function test_repeat_customer_flag_appears_when_sibling_in_exception(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		// Create two orders for the same customer.
		$order1       = $this->create_paid_order_for_customer( 1 );
		$fulfillment1 = $this->fulfillments->find_by_order_id( $order1->get_id() );

		$order2       = $this->create_paid_order_for_customer( 1, $order1->get_customer_id() );
		$fulfillment2 = $this->fulfillments->find_by_order_id( $order2->get_id() );

		self::assertNotNull( $fulfillment1 );
		self::assertNotNull( $fulfillment2 );
		self::assertSame( $order1->get_customer_id(), $order2->get_customer_id(), 'Both orders must belong to the same customer' );

		// Move the first order to an exception state (e.g., 'problem').
		$fulfillment1->apply_transition( 'problem', 'Test issue', new DateTimeImmutable() );
		$this->fulfillments->save( $fulfillment1 );

		// Render the second order's workspace.
		$_GET['fulfillment_id'] = (string) $fulfillment2->id();

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		// The repeat-customer flag should appear.
		self::assertStringContainsString( 'Repeat problem customer', $html );
		self::assertStringContainsString( 'dashicons-warning', $html );
	}

	/**
	 * Test repeat-customer flag does not appear when the only sibling is
	 * not in an exception state.
	 */
	public function test_repeat_customer_flag_absent_when_sibling_not_in_exception(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		// Create two orders for the same customer, both in normal state.
		$order1       = $this->create_paid_order_for_customer( 1 );
		$fulfillment1 = $this->fulfillments->find_by_order_id( $order1->get_id() );

		$order2       = $this->create_paid_order_for_customer( 1, $order1->get_customer_id() );
		$fulfillment2 = $this->fulfillments->find_by_order_id( $order2->get_id() );

		self::assertNotNull( $fulfillment1 );
		self::assertNotNull( $fulfillment2 );

		// Both remain in normal workflow state (queued → picking, etc.).
		// Neither is in an exception state.

		$_GET['fulfillment_id'] = (string) $fulfillment2->id();

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		// The repeat-customer flag should NOT appear.
		self::assertStringNotContainsString( 'Repeat problem customer', $html );
	}

	/**
	 * Test that guest checkout orders (customer_id = 0) do not trigger
	 * repeat-customer detection, and the workspace renders safely.
	 */
	public function test_guest_checkout_order_renders_without_repeat_customer_check(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		// Create a guest checkout order.
		$order       = $this->create_paid_order( 1 );
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );

		self::assertNotNull( $fulfillment );
		self::assertSame( 0, $order->get_customer_id(), 'Test order must be a guest checkout' );

		$_GET['fulfillment_id'] = (string) $fulfillment->id();

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		// Should render without fatal, and no repeat-customer flag.
		self::assertStringContainsString( 'mpcf-ui-workspace-layout', $html );
		self::assertStringNotContainsString( 'Repeat problem customer', $html );
	}
}
