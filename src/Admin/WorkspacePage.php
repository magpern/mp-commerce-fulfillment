<?php
/**
 * The Packing Workspace admin screen.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

use MPCF\Application\AssignmentService;
use MPCF\Application\AvailableTransition;
use MPCF\Application\FulfillmentDetailService;
use MPCF\Application\FulfillmentDetailView;
use MPCF\Application\NoteService;
use MPCF\Application\ShippingService;
use MPCF\Application\WorkflowService;
use MPCF\Capabilities;
use MPCF\Domain\CarrierRegistry;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\OrderSource;
use MPCF\Domain\Shipping\Package;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Domain\Workflow\State;
use MPCF\Domain\Workflow\WorkflowDefinition;
use MPCF\Vendor\Mpds\ComponentRenderer;
use MPCF\Vendor\Mpds\PageShell\AdminPageShell;
use MPCF\Vendor\Mpds\PageShell\Page;
use MPCF\Woo\StoreUnits;
use WP_User;

/**
 * Architecture Plan §IV.5: "the workspace is for doing" — everything on
 * this screen that mutates state does so through `mpcf/v1` (I11), never a
 * form POST this class handles itself; that is what makes this genuinely
 * the same public surface a future tablet PWA would use (§9.5). This
 * class renders the *initial* server-side state only (fast first paint,
 * no skeleton flash, §IV.5.1) — every interactive behavior (quantity
 * increments, the shipment/package panel's live edits, the action bar's
 * primary button, the scan sink) is wired by the workspace JS modules
 * landing in F16-F19; until then every control on this page is
 * functionally inert, by design, the same way `mpcf/v1` itself shipped
 * with nothing rendering against it yet (F8-F11).
 *
 * Registered as a real hidden submenu page, exactly like
 * {@see FulfillmentDetailPage} — reachable by URL and capability-checked,
 * never a visible nav item (§9.3/§IV.5.1).
 */
final class WorkspacePage implements Page {

	/**
	 * This page's slug.
	 */
	public const SLUG = 'mpcf-workspace';

	/**
	 * The successful terminal state appended to the stepper's forward
	 * path, when the governing workflow declares one — see
	 * {@see build_stepper_steps()}.
	 */
	private const STEPPER_TERMINAL_STATE = 'completed';

	/**
	 * Page-shell chrome renderer.
	 *
	 * @var AdminPageShell
	 */
	private AdminPageShell $shell;

	/**
	 * MPDS component renderer.
	 *
	 * @var ComponentRenderer
	 */
	private ComponentRenderer $renderer;

	/**
	 * Read-side fulfillment aggregation.
	 *
	 * @var FulfillmentDetailService
	 */
	private FulfillmentDetailService $detail;

	/**
	 * Transition candidate evaluation.
	 *
	 * @var WorkflowService
	 */
	private WorkflowService $workflow;

	/**
	 * Shipment/package reads.
	 *
	 * @var ShippingService
	 */
	private ShippingService $shipping;

	/**
	 * Note reads.
	 *
	 * @var NoteService
	 */
	private NoteService $notes;

	/**
	 * Carrier data.
	 *
	 * @var CarrierRegistry
	 */
	private CarrierRegistry $carriers;

	/**
	 * The soft-claim mechanism (§IV.5.7).
	 *
	 * @var AssignmentService
	 */
	private AssignmentService $assignments;

	/**
	 * A live read of the owning order, for its ship-to address.
	 *
	 * @var OrderSource
	 */
	private OrderSource $orders;

	/**
	 * The governing workflow.
	 *
	 * @var WorkflowDefinition
	 */
	private WorkflowDefinition $definition;

	/**
	 * The store's configured weight/dimension display units (§IV.6).
	 *
	 * @var StoreUnits
	 */
	private StoreUnits $units;

	/**
	 * Builds the page.
	 *
	 * @param AdminPageShell           $shell       Page-shell chrome renderer.
	 * @param ComponentRenderer        $renderer    MPDS component renderer.
	 * @param FulfillmentDetailService $detail      Read-side fulfillment aggregation.
	 * @param WorkflowService          $workflow    Transition candidate evaluation.
	 * @param ShippingService          $shipping    Shipment/package reads.
	 * @param NoteService              $notes       Note reads.
	 * @param CarrierRegistry          $carriers    Carrier data.
	 * @param AssignmentService        $assignments The soft-claim mechanism.
	 * @param OrderSource              $orders      A live read of the owning order, for its ship-to address.
	 * @param WorkflowDefinition       $definition  The governing workflow.
	 * @param StoreUnits               $units       The store's configured weight/dimension display units.
	 */
	public function __construct(
		AdminPageShell $shell,
		ComponentRenderer $renderer,
		FulfillmentDetailService $detail,
		WorkflowService $workflow,
		ShippingService $shipping,
		NoteService $notes,
		CarrierRegistry $carriers,
		AssignmentService $assignments,
		OrderSource $orders,
		WorkflowDefinition $definition,
		StoreUnits $units
	) {
		$this->shell       = $shell;
		$this->renderer    = $renderer;
		$this->detail      = $detail;
		$this->workflow    = $workflow;
		$this->shipping    = $shipping;
		$this->notes       = $notes;
		$this->carriers    = $carriers;
		$this->assignments = $assignments;
		$this->orders      = $orders;
		$this->definition  = $definition;
		$this->units       = $units;
	}

	/**
	 * This page's slug.
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * The browser page title.
	 */
	public function title(): string {
		return __( 'Packing Workspace', 'mp-commerce-fulfillment' );
	}

	/**
	 * The submenu label.
	 */
	public function menu_title(): string {
		return __( 'Packing Workspace', 'mp-commerce-fulfillment' );
	}

	/**
	 * The capability required to view this page. Every mutating control
	 * on it re-checks its own capability server-side, at the `mpcf/v1`
	 * route it calls — this page-level check only gates visibility.
	 */
	public function capability(): string {
		return Capabilities::VIEW_QUEUE;
	}

	/**
	 * Renders the page body.
	 */
	public function render(): void {
		$fulfillment_id = isset( $_GET['fulfillment_id'] ) ? (int) $_GET['fulfillment_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation param.

		$this->shell->open_wrap();
		$this->shell->open();
		$this->shell->render_header( ShellHeader::view_model( QueuePage::SLUG ) );
		$this->shell->open_content( true );

		if ( $fulfillment_id > 0 ) {
			$this->maybe_take_over( $fulfillment_id );
		}

		$view = $fulfillment_id > 0 ? $this->detail->get( $fulfillment_id ) : null;

		if ( null === $view ) {
			echo $this->renderer->empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'dashicons-archive',
				__( 'No fulfillment selected', 'mp-commerce-fulfillment' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- empty_state() escapes every arg internally.
				__( 'Open a fulfillment from the Queue to start packing.', 'mp-commerce-fulfillment' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- empty_state() escapes every arg internally.
			);
		} else {
			$this->maybe_self_claim( $view->fulfillment() );
			$this->render_workspace( $view );
		}

		$this->shell->close_content();
		$this->shell->close();
		$this->shell->close_wrap();
	}

	/**
	 * Self-assigns an unassigned fulfillment to the current user, audited
	 * — the soft claim §IV.5.7 describes. Never overrides an existing
	 * assignment; that is {@see maybe_take_over()}'s job, on explicit
	 * request only.
	 *
	 * @param Fulfillment $fulfillment Fulfillment being opened.
	 */
	private function maybe_self_claim( Fulfillment $fulfillment ): void {
		if ( null !== $fulfillment->assignee_id() ) {
			return;
		}

		$this->assignments->assign( (int) $fulfillment->id(), get_current_user_id(), self::current_actor() );
	}

	/**
	 * Reassigns the fulfillment to the current user when `?take_over=1` is
	 * present — a real, bookmarkable link (never a JS-only action), per
	 * the "real anchors everywhere" rule (§IV.5.6) applied to this
	 * non-blocking banner action too.
	 *
	 * @param int $fulfillment_id Fulfillment being opened.
	 */
	private function maybe_take_over( int $fulfillment_id ): void {
		if ( ! isset( $_GET['take_over'] ) || ! current_user_can( Capabilities::PROCESS_FULFILLMENTS ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Idempotent reassignment to the current user; no nonce carries meaningfully more protection here than the capability check already gives.
			return;
		}

		$this->assignments->assign( $fulfillment_id, get_current_user_id(), self::current_actor() );
	}

	/**
	 * Renders the three-region workspace layout and its action bar.
	 *
	 * @param FulfillmentDetailView $view Assembled detail view.
	 */
	private function render_workspace( FulfillmentDetailView $view ): void {
		$fulfillment = $view->fulfillment();
		$candidates  = $this->workflow->available_transitions( (int) $fulfillment->id(), 'current_user_can' );

		printf( '<form method="post" class="mpcf-workspace" data-mpcf-workspace data-mpcf-fulfillment-id="%d" data-mpcf-version="%d">', (int) $fulfillment->id(), $fulfillment->version() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both values are ints from typed method returns; %d already constrains the output.

		$this->render_assignment_banner( $fulfillment );

		echo $this->renderer->workspace_layout_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo $this->renderer->workspace_layout_region_open( 'context' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->render_context_region( $view );
		echo $this->renderer->workspace_layout_region_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo $this->renderer->workspace_layout_region_open( 'work' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->render_work_region( $fulfillment, $view );
		echo $this->renderer->workspace_layout_region_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo $this->renderer->workspace_layout_region_open( 'outcome' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->render_outcome_region( $fulfillment, $view );
		echo $this->renderer->workspace_layout_region_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo $this->renderer->workspace_layout_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$this->render_action_bar( $fulfillment, $candidates );
		$this->render_reason_modal();

		echo '</form>';

		echo $this->renderer->toast_region(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$this->render_shortcut_sheet();
	}

	/**
	 * Renders the `?` shortcut sheet — a modal listing the full keyboard
	 * map (Architecture Plan §IV.5.3), composed from the existing MPDS
	 * `modal` + `kbd-hints` components, no new component needed. Static
	 * content, independent of the fulfillment being viewed.
	 */
	private function render_shortcut_sheet(): void {
		echo $this->renderer->modal_open( 'mpcf-shortcut-sheet', __( 'Keyboard shortcuts', 'mp-commerce-fulfillment' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->kbd_hints_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		foreach ( $this->shortcut_map() as $entry ) {
			echo $this->renderer->kbd_hint( $entry[0], $entry[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo $this->renderer->kbd_hints_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->modal_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * The full keyboard map (Architecture Plan §IV.5.3) — the single
	 * source both this shortcut sheet and `assets/admin/js/shortcuts.js`'s
	 * key bindings are written against.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function shortcut_map(): array {
		return array(
			array( 'Ctrl/Cmd + Enter', __( 'Primary action', 'mp-commerce-fulfillment' ) ),
			array( 'j / k', __( 'Move item focus down / up', 'mp-commerce-fulfillment' ) ),
			array( 'Space / Enter', __( 'Increment focused line', 'mp-commerce-fulfillment' ) ),
			array( 'Shift + Space', __( 'Decrement focused line', 'mp-commerce-fulfillment' ) ),
			array( 'a', __( 'Complete focused line', 'mp-commerce-fulfillment' ) ),
			array( 'Shift + A', __( 'Complete all lines', 'mp-commerce-fulfillment' ) ),
			array( 'c', __( 'Toggle collapse-completed', 'mp-commerce-fulfillment' ) ),
			array( '/', __( 'Focus the scan sink', 'mp-commerce-fulfillment' ) ),
			array( 't', __( 'Focus tracking number', 'mp-commerce-fulfillment' ) ),
			array( 'w', __( 'Focus package 1 weight', 'mp-commerce-fulfillment' ) ),
			array( 'n', __( 'Focus new-note field', 'mp-commerce-fulfillment' ) ),
			array( 'p', __( 'Open the Problem dialog', 'mp-commerce-fulfillment' ) ),
			array( 'Shift + P', __( 'Print packing slip', 'mp-commerce-fulfillment' ) ),
			array( '[ / ]', __( 'Previous / next fulfillment in the queue', 'mp-commerce-fulfillment' ) ),
			array( '?', __( 'This shortcut sheet', 'mp-commerce-fulfillment' ) ),
			array( 'Esc', __( 'Close dialog, or return to scan sink', 'mp-commerce-fulfillment' ) ),
		);
	}

	/**
	 * A non-blocking banner naming who a fulfillment is currently assigned
	 * to, with a take-over link, when that is someone other than the
	 * viewer (§IV.5.7 — "no hard lock").
	 *
	 * @param Fulfillment $fulfillment Fulfillment being detailed.
	 */
	private function render_assignment_banner( Fulfillment $fulfillment ): void {
		$assignee_id = $fulfillment->assignee_id();

		if ( null === $assignee_id || get_current_user_id() === $assignee_id ) {
			return;
		}

		$assignee      = get_userdata( $assignee_id );
		$name          = $assignee instanceof WP_User ? $assignee->display_name : __( 'another operator', 'mp-commerce-fulfillment' );
		$take_over_url = add_query_arg( 'take_over', '1' );

		echo $this->renderer->info_panel( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- info_panel() escapes every arg internally; see per-line notes below.
			sprintf(
				/* translators: %s: assignee display name */
				esc_html__( 'Assigned to %s', 'mp-commerce-fulfillment' ),
				esc_html( $name )
			), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.
			__( 'You can take over without losing any work already saved.', 'mp-commerce-fulfillment' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- info_panel() escapes every arg internally.
			sprintf( '<a class="button" href="%s">%s</a>', esc_url( $take_over_url ), esc_html__( 'Take over', 'mp-commerce-fulfillment' ) )
		);
	}

	/**
	 * Renders the context (left) region: order identity, ship-to address,
	 * flags, and pinned notes.
	 *
	 * @param FulfillmentDetailView $view Assembled detail view.
	 */
	private function render_context_region( FulfillmentDetailView $view ): void {
		$fulfillment = $view->fulfillment();

		printf( '<h2 class="mpcf-workspace__order-number">%s</h2>', esc_html( $fulfillment->order_number_snapshot() ) );
		printf( '<p class="mpcf-workspace__order-date">%s</p>', esc_html( $fulfillment->created_at()->format( get_option( 'date_format' ) ) ) );
		printf( '<p class="mpcf-workspace__channel">%s</p>', esc_html( ucfirst( $fulfillment->order_source() ) ) );

		if ( current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- A real order-platform capability (shop_manager/administrator only), not a typo.
			printf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $fulfillment->order_id() ) ),
				esc_html__( 'View order', 'mp-commerce-fulfillment' )
			);
		}

		$order = $this->orders->find( $fulfillment->order_id() );

		echo '<h3>' . esc_html__( 'Ship to', 'mp-commerce-fulfillment' ) . '</h3>';
		echo '<address class="mpcf-workspace__ship-to">';
		foreach ( null !== $order ? $order->ship_to_lines() : array() as $line ) {
			echo esc_html( $line ) . '<br>';
		}
		echo '</address>';

		/**
		 * Filters the context-column flags shown for one fulfillment in the
		 * Packing Workspace (Architecture Plan §9.4/§IV.5.2's named
		 * extension slot). Each entry is `array{icon: string, label: string}`
		 * — `icon` a dashicon class, `label` the display text. Milestone 2
		 * bundles three via {@see \MPCF\Woo\WorkspaceFlags}: a customer
		 * note present, high order value, and a repeat problem customer.
		 *
		 * @since 0.2.0
		 *
		 * @param array<int, array{icon: string, label: string}> $flags          Flags collected so far.
		 * @param int                                              $fulfillment_id Fulfillment being flagged.
		 */
		$flags = apply_filters( 'mpcf_workspace_flags', array(), (int) $fulfillment->id() );

		if ( array() !== $flags ) {
			echo '<ul class="mpcf-workspace__flags">';
			foreach ( $flags as $flag ) {
				printf(
					'<li><span class="dashicons %s" aria-hidden="true"></span> %s</li>',
					esc_attr( (string) ( $flag['icon'] ?? 'dashicons-flag' ) ),
					esc_html( (string) ( $flag['label'] ?? '' ) )
				);
			}
			echo '</ul>';
		}

		$pinned = array_values( array_filter( $view->notes(), static fn( $note ): bool => $note->is_pinned() ) );

		if ( array() !== $pinned ) {
			echo '<div class="mpcf-workspace__pinned-notes">';
			foreach ( $pinned as $note ) {
				printf( '<p class="mpcf-workspace__pinned-note">📌 %s</p>', esc_html( $note->body() ) );
			}
			echo '</div>';
		}
	}

	/**
	 * Renders the work (centre) region: the stepper, the item checklist,
	 * and the scan sink.
	 *
	 * @param Fulfillment           $fulfillment Fulfillment being packed.
	 * @param FulfillmentDetailView $view        Assembled detail view.
	 */
	private function render_work_region( Fulfillment $fulfillment, FulfillmentDetailView $view ): void {
		echo $this->renderer->stepper( $this->build_stepper_steps( $fulfillment->state() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$active_field = $this->active_quantity_field( $fulfillment->state() );

		echo $this->renderer->checklist_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		foreach ( $view->items() as $item ) {
			$qty_ordered = $item->qty_ordered();
			$qty_current = null === $active_field ? 0 : ( 'qty_picked' === $active_field ? $item->qty_picked() : $item->qty_packed() );

			$control = null !== $active_field
				? $this->renderer->quantity_stepper(
					"items[{$item->id()}][{$active_field}]",
					$qty_current,
					0,
					$qty_ordered,
					array( 'data-mpcf-item-id' => (string) $item->id() )
				)
				: sprintf( '%d / %d', $item->qty_picked(), $item->qty_ordered() ) . ' &middot; ' . sprintf( '%d / %d', $item->qty_packed(), $item->qty_ordered() );

			echo $this->renderer->checklist_row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'',
				esc_html( $item->name_snapshot() ),
				sprintf( '<code>%s</code>', esc_html( $item->sku_snapshot() ) ),
				$control, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above from escaped/renderer-escaped pieces only.
				null !== $active_field ? $qty_current >= $qty_ordered : ( $item->is_fully_picked() && $item->is_fully_packed() ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A bool, not markup; checklist_row()'s $complete parameter.
				array( 'data-mpcf-row-id' => (string) $item->id() ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- An int cast to string for an HTML attribute value; checklist_row() escapes every attribute internally.
			);
		}

		echo $this->renderer->checklist_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( null !== $active_field ) {
			printf( '<button type="button" class="button" data-mpcf-complete-all data-mpcf-field="%s">%s</button>', esc_attr( $active_field ), esc_html__( 'Complete all', 'mp-commerce-fulfillment' ) );
			printf( '<button type="button" class="button" data-mpcf-toggle-collapse-completed aria-pressed="false">%s</button>', esc_html__( 'Collapse completed', 'mp-commerce-fulfillment' ) );
		}

		echo $this->renderer->scan_input( 'scan' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Renders the outcome (right) region: shipment/package panel,
	 * documents, notes, and the recent timeline.
	 *
	 * @param Fulfillment           $fulfillment Fulfillment being packed.
	 * @param FulfillmentDetailView $view        Assembled detail view.
	 */
	private function render_outcome_region( Fulfillment $fulfillment, FulfillmentDetailView $view ): void {
		if ( current_user_can( Capabilities::MANAGE_SHIPMENTS ) ) {
			$this->render_shipment_panel( $fulfillment );
		}

		if ( current_user_can( Capabilities::RENDER_DOCUMENTS ) ) {
			echo '<h3>' . esc_html__( 'Documents', 'mp-commerce-fulfillment' ) . '</h3>';
			printf( '<button type="button" class="button" data-mpcf-print-packing-slip>%s</button>', esc_html__( 'Print packing slip', 'mp-commerce-fulfillment' ) );
		}

		if ( current_user_can( Capabilities::ADD_NOTES ) ) {
			$this->render_notes_section( $view );
		}

		echo '<h3>' . esc_html__( 'Timeline', 'mp-commerce-fulfillment' ) . '</h3>';
		echo $this->renderer->timeline_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		foreach ( array_slice( $view->timeline(), -5 ) as $event ) {
			$actor = '' !== (string) $event['actor_label_snapshot'] ? (string) $event['actor_label_snapshot'] : __( 'System', 'mp-commerce-fulfillment' );
			$when  = human_time_diff( strtotime( (string) $event['created_at'] ) ) . ' ' . __( 'ago', 'mp-commerce-fulfillment' );

			echo $this->renderer->timeline_item( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'dashicons-clock',
				$actor, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$when, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'<p>' . esc_html( (string) $event['event_type'] ) . '</p>'
			);
		}

		echo $this->renderer->timeline_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . FulfillmentDetailPage::SLUG . '&fulfillment_id=' . $fulfillment->id() ) ),
			esc_html__( 'View full audit trail', 'mp-commerce-fulfillment' )
		);
	}

	/**
	 * Renders the shipment/package panel: one card per shipment, each with
	 * its own carrier/tracking fields and a package repeater.
	 *
	 * @param Fulfillment $fulfillment Fulfillment being packed.
	 */
	private function render_shipment_panel( Fulfillment $fulfillment ): void {
		echo '<h3>' . esc_html__( 'Shipment', 'mp-commerce-fulfillment' ) . '</h3>';

		$rows = $this->shipping->list_for_fulfillment( (int) $fulfillment->id() );

		if ( array() === $rows ) {
			$this->render_new_shipment_card();
			return;
		}

		foreach ( $rows as $row ) {
			$this->render_shipment_card( $row['shipment'], $row['packages'] );
		}
	}

	/**
	 * Renders the "no shipment yet" card: bare carrier-select and
	 * tracking-number fields with no shipment id behind them yet. Per
	 * Architecture Plan §IV.5.8 step 6, the operator's first edit to
	 * either field is what creates the shipment (and its package 1) —
	 * `shipment.js` does that, then swaps `data-mpcf-shipment-id="0"` for
	 * the real id so every later edit on this card goes straight to
	 * `PATCH /shipments/{id}`.
	 */
	private function render_new_shipment_card(): void {
		echo '<div class="mpcf-workspace__shipment" data-mpcf-shipment-id="0">';
		echo '<p class="description">' . esc_html__( 'Enter a carrier or tracking number to create a shipment.', 'mp-commerce-fulfillment' ) . '</p>';

		echo '<label>' . esc_html__( 'Carrier', 'mp-commerce-fulfillment' ) . '</label>';
		echo '<select data-mpcf-carrier-select>';
		printf( '<option value="">%s</option>', esc_html__( '— Select —', 'mp-commerce-fulfillment' ) );
		foreach ( $this->carriers->all() as $carrier ) {
			printf( '<option value="%s">%s</option>', esc_attr( $carrier['id'] ), esc_html( $carrier['label'] ) );
		}
		echo '</select>';

		printf(
			'<label>%s</label><input type="text" placeholder="%s" data-mpcf-tracking-number>',
			esc_html__( 'Tracking number', 'mp-commerce-fulfillment' ),
			esc_attr__( 'Tracking number', 'mp-commerce-fulfillment' )
		);

		echo '<h4>' . esc_html__( 'Packages', 'mp-commerce-fulfillment' ) . '</h4>';
		printf(
			'<div data-mpcf-package-repeater data-mpcf-shipment-id="0" data-mpcf-grams-per-unit="%s" data-mpcf-mm-per-unit="%s" data-mpcf-weight-unit-label="%s" data-mpcf-dimension-unit-label="%s">',
			esc_attr( (string) $this->units->grams_per_display_unit() ),
			esc_attr( (string) $this->units->mm_per_display_unit() ),
			esc_attr( $this->units->weight_unit_label() ),
			esc_attr( $this->units->dimension_unit_label() )
		);
		echo $this->renderer->repeater_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->repeater_add_button( __( 'Add package', 'mp-commerce-fulfillment' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->repeater_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Renders one shipment card. `data-mpcf-shipment-service` round-trips
	 * the shipment's `service` value — there is no visible field for it in
	 * M2, but `PATCH /shipments/{id}` has no partial-update semantics for
	 * `carrier_id`/`service` (an omitted field is submitted as `''` by the
	 * route's own arg defaults), so `shipment.js` must resend the current
	 * value on every edit or silently blank it.
	 *
	 * @param Shipment            $shipment Shipment to render.
	 * @param array<int, Package> $packages Its packages.
	 */
	private function render_shipment_card( Shipment $shipment, array $packages ): void {
		printf(
			'<div class="mpcf-workspace__shipment" data-mpcf-shipment-id="%d" data-mpcf-shipment-service="%s">',
			(int) $shipment->id(),
			esc_attr( $shipment->service() )
		);

		echo '<label>' . esc_html__( 'Carrier', 'mp-commerce-fulfillment' ) . '</label>';
		echo '<select data-mpcf-carrier-select>';
		foreach ( $this->carriers->all() as $carrier ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $carrier['id'] ),
				selected( $carrier['id'], $shipment->carrier_id(), false ),
				esc_html( $carrier['label'] )
			);
		}
		echo '</select>';

		printf(
			'<label>%s</label><input type="text" value="%s" data-mpcf-tracking-number>',
			esc_html__( 'Tracking number', 'mp-commerce-fulfillment' ),
			esc_attr( (string) $shipment->tracking()->number() )
		);

		echo '<h4>' . esc_html__( 'Packages', 'mp-commerce-fulfillment' ) . '</h4>';
		printf(
			'<div data-mpcf-package-repeater data-mpcf-shipment-id="%d" data-mpcf-grams-per-unit="%s" data-mpcf-mm-per-unit="%s" data-mpcf-weight-unit-label="%s" data-mpcf-dimension-unit-label="%s">',
			(int) $shipment->id(),
			esc_attr( (string) $this->units->grams_per_display_unit() ),
			esc_attr( (string) $this->units->mm_per_display_unit() ),
			esc_attr( $this->units->weight_unit_label() ),
			esc_attr( $this->units->dimension_unit_label() )
		);
		echo $this->renderer->repeater_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		foreach ( $packages as $package ) {
			$this->render_package_item( $package );
		}

		echo $this->renderer->repeater_add_button( __( 'Add package', 'mp-commerce-fulfillment' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->repeater_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Renders one package's fields inside the repeater. Displayed values
	 * are converted to the store's configured weight/dimension units
	 * ({@see StoreUnits}) even though each field's `name` still names the
	 * canonical grams/millimetres it maps to — `shipment.js` converts back
	 * before sending `PATCH /packages/{id}`, using the repeater wrapper's
	 * `data-mpcf-grams-per-unit`/`data-mpcf-mm-per-unit` factors.
	 *
	 * @param Package $package Package to render.
	 */
	private function render_package_item( Package $package ): void {
		$spec = $package->spec();
		$id   = (int) $package->id();

		$body = $this->renderer->unit_input( "packages[{$id}][weight_grams]", $this->units->grams_to_display( $spec->weight_grams() ), $this->units->weight_unit_label(), array( 'data-mpcf-package-field' => 'weight_grams' ) )
			. $this->renderer->unit_input( "packages[{$id}][length_mm]", $this->units->mm_to_display( $spec->length_mm() ), $this->units->dimension_unit_label(), array( 'data-mpcf-package-field' => 'length_mm' ) )
			. $this->renderer->unit_input( "packages[{$id}][width_mm]", $this->units->mm_to_display( $spec->width_mm() ), $this->units->dimension_unit_label(), array( 'data-mpcf-package-field' => 'width_mm' ) )
			. $this->renderer->unit_input( "packages[{$id}][height_mm]", $this->units->mm_to_display( $spec->height_mm() ), $this->units->dimension_unit_label(), array( 'data-mpcf-package-field' => 'height_mm' ) )
			. sprintf(
				'<input type="text" name="packages[%d][tracking_number]" value="%s" placeholder="%s" data-mpcf-package-field="tracking_number">',
				$id,
				esc_attr( (string) $package->tracking_number() ),
				esc_attr__( 'Colli tracking number', 'mp-commerce-fulfillment' )
			);

		echo $this->renderer->repeater_item( $body, array( 'data-mpcf-package-id' => (string) $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Renders the notes section: existing notes (pinned first) plus an
	 * add-note field.
	 *
	 * @param FulfillmentDetailView $view Assembled detail view.
	 */
	private function render_notes_section( FulfillmentDetailView $view ): void {
		echo '<h3>' . esc_html__( 'Notes', 'mp-commerce-fulfillment' ) . '</h3>';

		foreach ( $view->notes() as $note ) {
			$author = get_userdata( $note->author_id() );
			$pinned = $note->is_pinned() ? ' <strong>(' . esc_html__( 'pinned', 'mp-commerce-fulfillment' ) . ')</strong>' : '';

			printf(
				'<p>%s%s — %s</p>',
				esc_html( $author instanceof WP_User ? $author->display_name : __( 'Unknown', 'mp-commerce-fulfillment' ) ),
				wp_kses_post( $pinned ),
				esc_html( $note->body() )
			);
		}

		printf(
			'<textarea name="new_note" rows="2" placeholder="%s" data-mpcf-new-note></textarea>',
			esc_attr__( 'Add a note…', 'mp-commerce-fulfillment' )
		);
		printf( '<button type="button" class="button" data-mpcf-add-note>%s</button>', esc_html__( 'Add note', 'mp-commerce-fulfillment' ) );
	}

	/**
	 * Renders the sticky action bar: identity, the Problem… reason-modal
	 * trigger, every other approved candidate as a secondary button, and
	 * the one primary button (Architecture Plan §IV.5.2).
	 *
	 * @param Fulfillment                     $fulfillment Fulfillment being packed.
	 * @param array<int, AvailableTransition> $candidates  Every candidate transition, already evaluated.
	 */
	private function render_action_bar( Fulfillment $fulfillment, array $candidates ): void {
		$state    = $this->definition->has_state( $fulfillment->state() ) ? $this->definition->state( $fulfillment->state() ) : null;
		$identity = esc_html( $fulfillment->order_number_snapshot() );

		if ( null !== $state ) {
			$identity .= ' ' . $this->renderer->status_badge( $state->label(), $state->badge_variant() );
		}

		echo $this->renderer->action_bar_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->action_bar_identity( $identity ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$this->render_queue_cursor( (int) $fulfillment->id() );

		printf(
			'<button type="button" class="button-link" data-mpcf-modal-open="mpcf-shortcut-sheet" aria-label="%s">%s</button>',
			esc_attr__( 'Keyboard shortcuts', 'mp-commerce-fulfillment' ),
			esc_html__( '? Shortcuts', 'mp-commerce-fulfillment' )
		);

		echo $this->renderer->action_bar_actions_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$primary     = $this->choose_primary_candidate( $candidates );
		$secondaries = array_filter( $candidates, static fn( $candidate ) => $candidate !== $primary );

		foreach ( $secondaries as $candidate ) {
			if ( ! $candidate->is_approved() ) {
				continue;
			}

			printf(
				'<button type="button" class="button" data-mpcf-secondary-action data-mpcf-target="%s"%s>%s</button>',
				esc_attr( $candidate->target() ),
				$candidate->requires_reason() ? ' data-mpcf-requires-reason' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed literal attribute string, not user input.
				esc_html( $candidate->label() )
			);
		}

		if ( null !== $primary ) {
			$attrs = array( 'data-mpcf-target' => $primary->target() );

			if ( $primary->requires_reason() ) {
				$attrs['data-mpcf-requires-reason'] = '1';
			}

			if ( $primary->is_approved() ) {
				echo $this->renderer->action_bar_primary( $primary->label(), $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				$attrs['disabled'] = 'disabled';
				echo $this->renderer->action_bar_primary( $primary->label(), $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				printf( '<span class="description" data-mpcf-guard-message>%s</span>', esc_html( (string) $primary->rejection_message() ) );
			}
		}

		echo $this->renderer->action_bar_actions_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->action_bar_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Renders Previous/Next navigation within the queue's current filter/
	 * sort slice, when the workspace was opened with one (Architecture
	 * Plan §IV.5.3 — the `[`/`]` shortcuts and `shortcuts.js` both target
	 * these same real anchors). The cursor is an opaque comma-separated id
	 * list the Queue screen builds (§IV.5.1) — this page only ever reads
	 * its own position in it, never how it was built.
	 *
	 * @param int $fulfillment_id Fulfillment currently open.
	 */
	private function render_queue_cursor( int $fulfillment_id ): void {
		$cursor = isset( $_GET['cursor'] ) ? sanitize_text_field( wp_unslash( $_GET['cursor'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation param.

		if ( '' === $cursor ) {
			return;
		}

		$ids      = array_values( array_filter( array_map( 'absint', explode( ',', $cursor ) ) ) );
		$position = array_search( $fulfillment_id, $ids, true );

		if ( false === $position ) {
			return;
		}

		echo '<nav class="mpcf-workspace__queue-cursor">';

		if ( $position > 0 ) {
			printf(
				'<a href="%s" data-mpcf-queue-prev>%s</a>',
				esc_url( $this->workspace_url( $ids[ $position - 1 ], $cursor ) ),
				esc_html__( '← Previous', 'mp-commerce-fulfillment' )
			);
		}

		if ( $position < count( $ids ) - 1 ) {
			printf(
				'<a href="%s" data-mpcf-queue-next>%s</a>',
				esc_url( $this->workspace_url( $ids[ $position + 1 ], $cursor ) ),
				esc_html__( 'Next →', 'mp-commerce-fulfillment' )
			);
		}

		echo '</nav>';
	}

	/**
	 * A workspace URL for another fulfillment, carrying the same cursor
	 * forward so repeated `]` presses keep walking the same queue slice.
	 *
	 * @param int    $fulfillment_id Target fulfillment.
	 * @param string $cursor         The opaque cursor string.
	 */
	private function workspace_url( int $fulfillment_id, string $cursor ): string {
		return add_query_arg(
			array(
				'page'           => self::SLUG,
				'fulfillment_id' => $fulfillment_id,
				'cursor'         => $cursor,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Renders the one reusable reason-capture modal every candidate
	 * transition with `requires_reason() === true` opens (Architecture
	 * Plan §IV.5.7) — `workspace.js` sets which target is pending before
	 * showing it and reads its textarea on confirm, so a single modal
	 * serves every exception-state edge without knowing in advance which
	 * ones the governing workflow declares.
	 */
	private function render_reason_modal(): void {
		echo $this->renderer->reason_modal( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'mpcf-reason-modal',
			__( 'Reason required', 'mp-commerce-fulfillment' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Passed as a method argument, not printed; reason_modal() escapes it internally.
			'reason',
			__( 'This action is recorded on the audit trail. Add a reason:', 'mp-commerce-fulfillment' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Passed as a method argument, not printed; reason_modal() escapes it internally.
			__( 'Confirm', 'mp-commerce-fulfillment' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Passed as a method argument, not printed; reason_modal() escapes it internally.
		);
	}

	/**
	 * Picks the one forward-path candidate the primary button represents —
	 * the first candidate whose target is neither an exception state nor
	 * `cancelled`. `available_transitions()` returns candidates in the
	 * governing workflow's own declaration order, which is what resolves
	 * `queued`'s two forward candidates (`picking`, its `packing` shortcut)
	 * to the one a typical store expects as primary.
	 *
	 * @param array<int, AvailableTransition> $candidates Every candidate transition.
	 */
	private function choose_primary_candidate( array $candidates ): ?AvailableTransition {
		foreach ( $candidates as $candidate ) {
			if ( ! $this->definition->has_state( $candidate->target() ) ) {
				continue;
			}

			$target_state = $this->definition->state( $candidate->target() );

			if ( $target_state->is_exception() || 'cancelled' === $candidate->target() ) {
				continue;
			}

			return $candidate;
		}

		return null;
	}

	/**
	 * Builds the stepper's ordered steps from the governing workflow's
	 * initial/working states, plus its successful terminal state if one
	 * is declared under the conventional key. When the fulfillment is
	 * currently in an exception state or cancelled, no step is marked
	 * current — the stepper shows the plain forward path instead of
	 * guessing which position an interrupted fulfillment "really" is at.
	 *
	 * @param string $current_state The fulfillment's current state key.
	 * @return array<int, array<string, string>>
	 */
	private function build_stepper_steps( string $current_state ): array {
		$keys = array();

		foreach ( $this->definition->states() as $state ) {
			if ( in_array( $state->type(), array( State::TYPE_INITIAL, State::TYPE_WORKING ), true ) ) {
				$keys[] = $state->key();
			}
		}

		if ( $this->definition->has_state( self::STEPPER_TERMINAL_STATE ) ) {
			$keys[] = self::STEPPER_TERMINAL_STATE;
		}

		$current_index = array_search( $current_state, $keys, true );
		$steps         = array();

		foreach ( $keys as $index => $key ) {
			if ( false === $current_index ) {
				$step_state = 'upcoming';
			} elseif ( $index < $current_index ) {
				$step_state = 'complete';
			} elseif ( $index === $current_index ) {
				$step_state = 'current';
			} else {
				$step_state = 'upcoming';
			}

			$steps[] = array(
				'label' => $this->definition->state( $key )->label(),
				'state' => $step_state,
			);
		}

		return $steps;
	}

	/**
	 * Which line-item quantity field is currently actionable: `qty_picked`
	 * while picking, `qty_packed` while packing, or null in every other
	 * state (nothing to increment, so the checklist renders read-only).
	 *
	 * @param string $state Fulfillment's current state key.
	 */
	private function active_quantity_field( string $state ): ?string {
		if ( 'picking' === $state ) {
			return 'qty_picked';
		}

		if ( 'packing' === $state ) {
			return 'qty_packed';
		}

		return null;
	}

	/**
	 * The current user as an {@see Actor}.
	 */
	private static function current_actor(): Actor {
		$user = wp_get_current_user();

		return Actor::user( $user->ID, $user->display_name );
	}
}
