<?php
/**
 * Integration tests for the Packing Workspace admin screen, against a
 * real database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Admin;

use DateTimeImmutable;
use MPCF\Admin\WorkspacePage;
use MPCF\Application\AssignmentService;
use MPCF\Application\EventDispatcher;
use MPCF\Application\FulfillmentDetailService;
use MPCF\Application\NoteService;
use MPCF\Application\ShippingService;
use MPCF\Application\WorkflowService;
use MPCF\Capabilities;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
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
use MPCF\Application\TransitionContextFactory;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Tests\Integration\Woo\OrderFactoryTrait;
use MPCF\Vendor\Mpds\ComponentRenderer;
use MPCF\Vendor\Mpds\PageShell\AdminPageShell;
use MPCF\Vendor\Mpds\PageShell\SectionNavigation;
use MPCF\Woo\WooOrderSource;
use WP_UnitTestCase;

/**
 * `render()` echoes directly, so every assertion captures output via
 * `ob_start()`/`ob_get_clean()` — the same technique other admin-screen
 * integration tests in this suite use where no return-value alternative
 * exists.
 */
final class WorkspacePageTest extends WP_UnitTestCase {

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
			new TransitionContextFactory( $this->items, $shipments, $packages )
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
			$definition
		);
	}

	private function seed( int $quantity = 2 ): int {
		$order       = $this->create_paid_order( $quantity );
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );

		return $fulfillment->id();
	}

	private function render_for( int $fulfillment_id ): string {
		$_GET['fulfillment_id'] = (string) $fulfillment_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test harness simulating a query param, not real request input.

		ob_start();
		$this->page->render();

		return (string) ob_get_clean();
	}

	public function test_render_shows_the_three_regions_and_key_data(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$id   = $this->seed();
		$html = $this->render_for( $id );

		self::assertStringContainsString( 'mpcf-ui-workspace-layout', $html );
		self::assertStringContainsString( 'mpcf-ui-action-bar', $html );
		self::assertStringContainsString( 'Test Widget', $html );
		self::assertStringContainsString( 'data-mpcf-scan-sink', $html );
		self::assertStringContainsString( 'data-mpcf-primary-action', $html );
	}

	public function test_render_shows_the_empty_state_without_a_fulfillment_id(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		unset( $_GET['fulfillment_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test harness cleanup.

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'No fulfillment selected', $html );
	}

	public function test_opening_an_unassigned_fulfillment_self_claims_it(): void {
		$user_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) );
		wp_set_current_user( $user_id );

		$id = $this->seed();
		self::assertNull( $this->fulfillments->find( $id )->assignee_id() );

		$this->render_for( $id );

		self::assertSame( $user_id, $this->fulfillments->find( $id )->assignee_id() );

		$timeline = $this->events->timeline_for_fulfillment( $id );
		$types    = array_column( $timeline, 'event_type' );
		self::assertContains( 'fulfillment.assigned', $types );
	}

	public function test_opening_a_fulfillment_already_assigned_to_someone_else_does_not_reassign_it(): void {
		$owner_id  = self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) );
		$viewer_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) );

		$id = $this->seed();
		$this->fulfillments->find( $id ); // Warm any lazy state; irrelevant to the assertion.

		wp_set_current_user( $owner_id );
		$this->render_for( $id );
		self::assertSame( $owner_id, $this->fulfillments->find( $id )->assignee_id() );

		wp_set_current_user( $viewer_id );
		$html = $this->render_for( $id );

		self::assertSame( $owner_id, $this->fulfillments->find( $id )->assignee_id(), 'Viewing someone else\'s claim must not silently reassign it.' );
		self::assertStringContainsString( 'Take over', $html );
	}

	public function test_take_over_reassigns_to_the_current_user(): void {
		$owner_id     = self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) );
		$new_owner_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) );

		$id = $this->seed();

		wp_set_current_user( $owner_id );
		$this->render_for( $id );
		self::assertSame( $owner_id, $this->fulfillments->find( $id )->assignee_id() );

		wp_set_current_user( $new_owner_id );
		$_GET['take_over'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test harness simulating the take-over query param.
		$this->render_for( $id );

		self::assertSame( $new_owner_id, $this->fulfillments->find( $id )->assignee_id() );
	}

	public function test_the_primary_button_is_disabled_with_the_guards_message_when_a_guard_blocks_it(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$id          = $this->seed( 2 );
		$fulfillment = $this->fulfillments->find( $id );
		$fulfillment->apply_transition( 'packing', null, new DateTimeImmutable() );
		$this->fulfillments->save( $fulfillment );

		$html = $this->render_for( $id );

		self::assertStringContainsString( 'disabled', $html );
		self::assertStringContainsString( 'must be fully packed', strtolower( $html ) );
	}
}
