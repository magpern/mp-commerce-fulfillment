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
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
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
use MPCF\Settings;
use MPCF\Vendor\Mpds\PageShell\SectionNavigation;
use MPCF\Woo\StoreUnits;
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

	private function seed( int $quantity = 2 ): int {
		$order       = $this->create_paid_order( $quantity );
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );

		return $fulfillment->id();
	}

	private function seed_with_customer_note( string $customer_note ): int {
		$order = $this->create_paid_order();
		$order->set_customer_note( $customer_note );
		$order->save();

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

	public function test_the_primary_button_disables_native_form_validation(): void {
		// The reason modal's hidden `required` textarea lives in this same
		// form; without `formnovalidate` the browser silently blocks every
		// submit on the form, including transitions that need no reason
		// at all — found by the Playwright suite's first real click on
		// this button, no PHPUnit test render ever submits a real form
		// (F22).
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$html = $this->render_for( $this->seed() );

		self::assertMatchesRegularExpression( '/data-mpcf-primary-action[^>]*formnovalidate/', $html );
	}

	public function test_render_shows_the_collapse_completed_toggle_while_a_quantity_field_is_active(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$id          = $this->seed();
		$fulfillment = $this->fulfillments->find( $id );
		$fulfillment->apply_transition( 'picking', null, new DateTimeImmutable() );
		$this->fulfillments->save( $fulfillment );

		$html = $this->render_for( $id );

		self::assertStringContainsString( 'data-mpcf-toggle-collapse-completed', $html );
	}

	public function test_render_stepper_includes_state_keys_for_client_side_refresh(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$id          = $this->seed();
		$fulfillment = $this->fulfillments->find( $id );
		$fulfillment->apply_transition( 'picking', null, new DateTimeImmutable() );
		$this->fulfillments->save( $fulfillment );

		$html = $this->render_for( $id );

		self::assertStringContainsString( 'data-mpcf-step-key="queued"', $html );
		self::assertStringContainsString( 'data-mpcf-step-key="picking"', $html );
		self::assertStringContainsString( 'mpcf-ui-stepper__step--current', $html );
	}

	public function test_render_includes_the_shortcut_sheet_modal_and_its_trigger(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$html = $this->render_for( $this->seed() );

		self::assertStringContainsString( 'data-mpcf-modal-open="mpcf-shortcut-sheet"', $html );
		self::assertStringContainsString( 'id="mpcf-shortcut-sheet"', $html );
		self::assertStringContainsString( 'mpcf-ui-kbd-hints', $html );
	}

	public function test_render_shows_the_new_shipment_card_when_none_exists_yet(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$html = $this->render_for( $this->seed() );

		self::assertStringContainsString( 'data-mpcf-shipment-id="0"', $html );
		self::assertStringContainsString( 'data-mpcf-carrier-select', $html );
		self::assertStringContainsString( 'data-mpcf-package-repeater', $html );
	}

	public function test_render_shows_the_package_repeater_with_the_stores_unit_labels(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );
		update_option( 'woocommerce_weight_unit', 'kg' );
		update_option( 'woocommerce_dimension_unit', 'cm' );

		$id      = $this->seed();
		$outcome = ( new ShippingService( $this->fulfillments, $this->items, new WpdbShipmentRepository(), new WpdbPackageRepository(), new WpdbPackageItemRepository(), $this->events, new EventDispatcher(), new SystemClock() ) )
			->create_shipment( $id, \MPCF\Domain\Event\Actor::system() );

		self::assertTrue( $outcome->is_success() );

		$html = $this->render_for( $id );

		self::assertStringContainsString( 'data-mpcf-grams-per-unit="1000"', $html );
		self::assertStringContainsString( 'data-mpcf-mm-per-unit="10"', $html );
		self::assertStringContainsString( 'data-mpcf-weight-unit-label="kg"', $html );
		self::assertStringContainsString( 'data-mpcf-dimension-unit-label="cm"', $html );
		self::assertStringContainsString( 'data-mpcf-photos', $html );
		self::assertStringContainsString( 'data-mpcf-can-delete="1"', $html );
		self::assertStringContainsString( 'data-mpcf-can-capture="1"', $html );
	}

	public function test_operator_photo_section_omits_delete_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$id      = $this->seed();
		$outcome = ( new ShippingService( $this->fulfillments, $this->items, new WpdbShipmentRepository(), new WpdbPackageRepository(), new WpdbPackageItemRepository(), $this->events, new EventDispatcher(), new SystemClock() ) )
			->create_shipment( $id, \MPCF\Domain\Event\Actor::system() );

		self::assertTrue( $outcome->is_success() );

		$html = $this->render_for( $id );

		self::assertStringContainsString( 'data-mpcf-photos', $html );
		self::assertStringContainsString( 'data-mpcf-can-capture="1"', $html );
		self::assertStringContainsString( 'data-mpcf-can-delete="0"', $html );
	}

	public function test_photos_required_banner_renders_when_setting_enabled(): void {
		$this->page = new WorkspacePage(
			new AdminPageShell( new SectionNavigation() ),
			new ComponentRenderer(),
			new FulfillmentDetailService( $this->fulfillments, $this->items, $this->events, new WpdbNoteRepository() ),
			new WorkflowService(
				$this->fulfillments,
				$this->events,
				new WorkflowEngine( GuardRegistry::standard() ),
				new EventDispatcher(),
				new SystemClock(),
				array( StandardWorkflow::NAME => StandardWorkflow::definition() ),
				new TransitionContextFactory( $this->items, new WpdbShipmentRepository(), new WpdbPackageRepository(), new Settings( array( 'photos_required' => true ) ) )
			),
			new ShippingService( $this->fulfillments, $this->items, new WpdbShipmentRepository(), new WpdbPackageRepository(), new WpdbPackageItemRepository(), $this->events, new EventDispatcher(), new SystemClock() ),
			new NoteService( new WpdbNoteRepository(), new SystemClock() ),
			new BundledCarrierRegistry(),
			new AssignmentService( $this->fulfillments, $this->events, new EventDispatcher(), new SystemClock() ),
			new WooOrderSource(),
			StandardWorkflow::definition(),
			new StoreUnits(),
			new \MPCF\Infrastructure\Database\WpdbDocumentRepository(),
			new Settings( array( 'photos_required' => true ) )
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$html = $this->render_for( $this->seed() );

		self::assertStringContainsString( 'data-mpcf-photo-requirement-banner', $html );
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

	public function test_the_rendered_version_is_not_stale_after_a_self_claim(): void {
		// The self-claim's own save() advances the fulfillment's version
		// (WpdbFulfillmentRepository::save() always does, deliberately —
		// see its own docblock). render() must re-fetch its view after
		// that happens, or the client's very first mutation attempt
		// would always 409 against a version the database has already
		// moved past — found via the Playwright suite's first real
		// transition attempt, not by any prior version of this test (F22).
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$id = $this->seed();

		$html = $this->render_for( $id );

		$real_version = $this->fulfillments->find( $id )->version();

		self::assertStringContainsString( 'data-mpcf-version="' . $real_version . '"', $html );
	}

	public function test_the_timeline_shows_only_the_5_most_recent_events(): void {
		// F23 (Architecture Plan §IV.10, risk M2-R11): the timeline region
		// used to fetch the fulfillment's *entire* chain and slice it down
		// to 5 in PHP; it now reads exactly 5 via its own bounded query.
		// Seeding 9 distinct, identifiable event types and asserting the 4
		// oldest are absent while the 5 newest are present is what
		// distinguishes "queried the last 5" from "queried everything and
		// happened to render fine" — a passing count alone couldn't.
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$id = $this->seed();
		$this->render_for( $id ); // Forces the self-claim first, so it doesn't land inside the 9 markers appended below.

		$prev_hash = $this->events->last_hash_for_fulfillment( $id );

		for ( $i = 0; $i < 9; $i++ ) {
			$prev_hash = $this->events->append(
				DomainEvent::for_fulfillment( $id, "test.timeline_marker_{$i}", Actor::system(), new DateTimeImmutable() ),
				$prev_hash
			);
		}

		$html = $this->render_for( $id );

		foreach ( range( 0, 3 ) as $i ) {
			self::assertStringNotContainsString( "test.timeline_marker_{$i}<", $html, "Marker {$i} is one of the 4 oldest and must not render." );
		}

		foreach ( range( 4, 8 ) as $i ) {
			self::assertStringContainsString( "test.timeline_marker_{$i}<", $html, "Marker {$i} is one of the 5 most recent and must render." );
		}
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

	public function test_render_includes_the_reusable_reason_modal(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$html = $this->render_for( $this->seed() );

		self::assertStringContainsString( 'id="mpcf-reason-modal"', $html );
		self::assertStringContainsString( 'name="reason"', $html );
	}

	public function test_render_marks_exception_transitions_as_requiring_a_reason(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$id          = $this->seed();
		$fulfillment = $this->fulfillments->find( $id );
		$fulfillment->apply_transition( 'picking', null, new DateTimeImmutable() );
		$this->fulfillments->save( $fulfillment );

		$html = $this->render_for( $id );

		self::assertStringContainsString( 'data-mpcf-target="problem"', $html );
		self::assertMatchesRegularExpression( '/data-mpcf-target="problem"[^>]*data-mpcf-requires-reason/', $html );
	}

	public function test_render_shows_queue_cursor_links_when_a_cursor_is_present(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$previous_id = $this->seed();
		$current_id  = $this->seed();
		$next_id     = $this->seed();

		$_GET['cursor'] = $previous_id . ',' . $current_id . ',' . $next_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test harness simulating the opaque cursor query param.

		$html = $this->render_for( $current_id );

		self::assertStringContainsString( 'data-mpcf-queue-prev', $html );
		self::assertStringContainsString( 'data-mpcf-queue-next', $html );
		self::assertStringContainsString( 'fulfillment_id=' . $previous_id, $html );
		self::assertStringContainsString( 'fulfillment_id=' . $next_id, $html );

		unset( $_GET['cursor'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test harness cleanup, so later tests in this class do not inherit this cursor.
	}

	public function test_render_omits_queue_cursor_links_without_a_cursor_param(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		unset( $_GET['cursor'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test harness cleanup.

		$html = $this->render_for( $this->seed() );

		self::assertStringNotContainsString( 'data-mpcf-queue-prev', $html );
		self::assertStringNotContainsString( 'data-mpcf-queue-next', $html );
	}

	public function test_the_primary_button_is_disabled_with_the_guards_message_when_a_guard_blocks_it(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$id          = $this->seed( 2 );
		$fulfillment = $this->fulfillments->find( $id );
		$fulfillment->apply_transition( 'packing', null, new DateTimeImmutable() );
		$this->fulfillments->save( $fulfillment );

		$html = $this->render_for( $id );

		self::assertStringContainsString( 'disabled', $html );
		self::assertStringContainsString( 'Pack all picked items before marking this fulfillment as packed.', $html );
	}

	public function test_render_shows_the_customer_note_when_present(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$html = $this->render_for( $this->seed_with_customer_note( 'Pack in green bag' ) );

		self::assertStringContainsString( 'Customer instructions', $html );
		self::assertStringContainsString( 'mpcf-workspace__customer-note', $html );
		self::assertStringContainsString( 'Pack in green bag', $html );
	}

	public function test_render_omits_the_customer_instructions_block_without_a_customer_note(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$html = $this->render_for( $this->seed() );

		self::assertStringNotContainsString( 'Customer instructions', $html );
		self::assertStringNotContainsString( 'mpcf-workspace__customer-instructions', $html );
	}

	public function test_render_preserves_multiline_customer_note_line_breaks(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$html = $this->render_for( $this->seed_with_customer_note( "Leave at door\nRing bell twice" ) );

		self::assertStringContainsString( 'Leave at door<br>', $html );
		self::assertStringContainsString( 'Ring bell twice', $html );
	}

	public function test_queued_workspace_renders_stage_banner_and_start_picking_guidance(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$html = $this->render_for( $this->seed() );

		self::assertStringContainsString( 'data-mpcf-stage-banner', $html );
		self::assertStringContainsString( 'data-mpcf-stage="queued"', $html );
		self::assertStringContainsString( 'Start picking to record the items you collect.', $html );
		self::assertStringContainsString( 'Start picking to enable quantity recording', $html );
		self::assertStringContainsString( 'mpcf-workspace__shipment-disclosure--muted', $html );
		self::assertStringNotContainsString( 'mpcf-workspace__shipment-disclosure--muted" open', $html );
	}

	public function test_picking_workspace_shows_ordered_picked_and_remaining(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$id          = $this->seed( 3 );
		$fulfillment = $this->fulfillments->find( $id );
		$fulfillment->apply_transition( 'picking', null, new DateTimeImmutable() );
		$this->fulfillments->save( $fulfillment );

		$html = $this->render_for( $id );

		self::assertStringContainsString( 'Ordered: 3', $html );
		self::assertStringContainsString( 'Picked: 0', $html );
		self::assertStringContainsString( 'Remaining: 3', $html );
		self::assertStringContainsString( 'Record each item as it is picked.', $html );
	}

	public function test_packing_workspace_shows_ordered_packed_remaining_and_open_shipment(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$id          = $this->seed( 2 );
		$fulfillment = $this->fulfillments->find( $id );
		$fulfillment->apply_transition( 'packing', null, new DateTimeImmutable() );
		$this->fulfillments->save( $fulfillment );

		$html = $this->render_for( $id );

		self::assertStringContainsString( 'Ordered: 2', $html );
		self::assertStringContainsString( 'Packed: 0', $html );
		self::assertStringContainsString( 'Remaining: 2', $html );
		self::assertStringContainsString( 'mpcf-workspace__shipment-disclosure--secondary', $html );
		self::assertMatchesRegularExpression( '/mpcf-workspace__shipment-disclosure--secondary"[^>]*\sopen/', $html );
	}

	public function test_packed_workspace_emphasizes_shipment_disclosure(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$id          = $this->seed();
		$fulfillment = $this->fulfillments->find( $id );
		foreach ( array( 'picking', 'picked', 'packing', 'packed' ) as $state ) {
			$fulfillment->apply_transition( $state, null, new DateTimeImmutable() );
		}
		$this->fulfillments->save( $fulfillment );

		$html = $this->render_for( $id );

		self::assertStringContainsString( 'Confirm shipment and tracking details, then ship the order.', $html );
		self::assertStringContainsString( 'mpcf-workspace__shipment-disclosure--primary', $html );
	}

	public function test_shipped_workspace_renders_success_panel(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$id          = $this->seed();
		$fulfillment = $this->fulfillments->find( $id );
		foreach ( array( 'picking', 'picked', 'packing', 'packed', 'shipped' ) as $state ) {
			$fulfillment->apply_transition( $state, null, new DateTimeImmutable() );
		}
		$this->fulfillments->save( $fulfillment );

		$html = $this->render_for( $id );

		self::assertStringContainsString( 'data-mpcf-shipped-success', $html );
		self::assertStringContainsString( 'This fulfillment has been shipped.', $html );
		self::assertStringContainsString( 'data-mpcf-shipped-next-order', $html );
		self::assertStringContainsString( 'data-mpcf-stage="shipped"', $html );
	}

	public function test_customer_instructions_render_in_work_region_callout(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$html = $this->render_for( $this->seed_with_customer_note( 'Fragile — handle with care' ) );

		self::assertStringContainsString( 'data-mpcf-customer-instructions', $html );
		self::assertStringContainsString( 'mpcf-ui-panel--warning', $html );
		self::assertStringContainsString( 'Fragile — handle with care', $html );
	}
}
