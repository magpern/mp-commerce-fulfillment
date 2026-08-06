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
use MPCF\Documents\DocumentEventLabels;
use MPCF\Admin\NotificationEventLabels;
use MPCF\Admin\PhotoEventLabels;
use MPCF\Documents\DocumentPrintContext;
use MPCF\Domain\CarrierRegistry;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\OrderSnapshot;
use MPCF\Domain\OrderSource;
use MPCF\Domain\Repository\DocumentRepository;
use MPCF\Domain\Shipping\Package;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Domain\Workflow\State;
use MPCF\Domain\Workflow\WorkflowDefinition;
use MPCF\Settings;
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
	 * Document generation records (last-printed status).
	 *
	 * @var DocumentRepository
	 */
	private DocumentRepository $documents;

	/**
	 * Plugin settings (photo requirement / limits for M6-C markup).
	 *
	 * @var Settings
	 */
	private Settings $settings;

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
	 * @param DocumentRepository       $documents   Document generation records.
	 * @param Settings                 $settings    Plugin settings.
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
		StoreUnits $units,
		DocumentRepository $documents,
		Settings $settings
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
		$this->documents   = $documents;
		$this->settings    = $settings;
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
			// A self-claim mutates (assign() saves the fulfillment, which
			// always advances `version` — the repository's own documented
			// save behavior). $view was fetched before it ran,
			// so it must be re-fetched afterward or the page bakes in a
			// version the database has already moved past — the client's
			// very first mutation would then always get a 409, since
			// every previously-unassigned fulfillment self-claims on open
			// (the common case). Found by the Playwright suite's first
			// real transition attempt ever made against this page (F22);
			// no PHPUnit test exercises version staleness this way, since
			// none reads `$view` back out of `render()` to compare it
			// against the database afterward.
			if ( $this->maybe_self_claim( $view->fulfillment() ) ) {
				$view = $this->detail->get( $fulfillment_id ) ?? $view;
			}

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
	 * @return bool Whether an assignment was actually made (the caller
	 *              must re-fetch its already-loaded view when true, since
	 *              this advances the fulfillment's version).
	 */
	private function maybe_self_claim( Fulfillment $fulfillment ): bool {
		if ( null !== $fulfillment->assignee_id() ) {
			return false;
		}

		return $this->assignments->assign( (int) $fulfillment->id(), get_current_user_id(), self::current_actor() );
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
		$fulfillment   = $view->fulfillment();
		$candidates    = $this->workflow->available_transitions( (int) $fulfillment->id(), 'current_user_can' );
		$primary       = $this->choose_primary_candidate( $candidates );
		$guidance      = WorkspaceStageGuidance::for_state( $fulfillment->state(), $primary, $this->definition );
		$customer_note = '';
		$order         = $this->orders->find( $fulfillment->order_id() );

		if ( null !== $order ) {
			$customer_note = $order->customer_note();
		}

		printf(
			'<form method="post" class="mpcf-workspace" data-mpcf-workspace data-mpcf-fulfillment-id="%d" data-mpcf-version="%d" data-mpcf-stage="%s">',
			(int) $fulfillment->id(),
			(int) $fulfillment->version(),
			esc_attr( $fulfillment->state() )
		);

		$this->render_assignment_banner( $fulfillment );
		$this->render_stage_banner( $guidance, $primary );
		$this->render_shipped_success_panel( $fulfillment );

		echo $this->renderer->workspace_layout_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo $this->renderer->workspace_layout_region_open( 'context' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->render_context_region( $view, $order );
		echo $this->renderer->workspace_layout_region_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo $this->renderer->workspace_layout_region_open( 'work' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->render_work_region( $fulfillment, $view, $customer_note );
		echo $this->renderer->workspace_layout_region_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo $this->renderer->workspace_layout_region_open( 'outcome' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->render_outcome_region( $fulfillment, $view );
		echo $this->renderer->workspace_layout_region_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo $this->renderer->workspace_layout_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$this->render_action_bar( $fulfillment, $candidates, $primary );
		$this->render_reason_modal();
		$this->render_stage_guidance_data();

		echo '</form>';

		echo $this->renderer->toast_region(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$this->render_shortcut_sheet();
	}

	/**
	 * Prominent stage banner: where am I, what should I do next.
	 *
	 * @param array{state_key: string, state_label: string, title: string, instruction: string, next_action_label: string, shipment_emphasis: string} $guidance Stage copy.
	 * @param AvailableTransition|null                                                                                                                $primary  Primary forward candidate.
	 */
	private function render_stage_banner( array $guidance, ?AvailableTransition $primary ): void {
		echo '<section class="mpcf-workspace__stage-banner" data-mpcf-stage-banner aria-live="polite">';
		printf(
			'<p class="mpcf-workspace__stage-state"><span class="mpcf-workspace__stage-state-label">%s</span> <span data-mpcf-stage-state-value>%s</span></p>',
			esc_html__( 'State', 'mp-commerce-fulfillment' ),
			esc_html( $guidance['state_label'] )
		);
		printf( '<h2 class="mpcf-workspace__stage-title" data-mpcf-stage-title>%s</h2>', esc_html( $guidance['title'] ) );
		printf( '<p class="mpcf-workspace__stage-instruction" data-mpcf-stage-instruction>%s</p>', esc_html( $guidance['instruction'] ) );

		if ( null !== $primary ) {
			printf(
				'<p class="mpcf-workspace__stage-next"><span class="mpcf-workspace__stage-next-label">%s</span> <strong data-mpcf-stage-next-action>%s</strong></p>',
				esc_html__( 'Next action', 'mp-commerce-fulfillment' ),
				esc_html( $primary->label() )
			);
		} elseif ( '' !== (string) $guidance['next_action_label'] ) {
			printf(
				'<p class="mpcf-workspace__stage-next"><span class="mpcf-workspace__stage-next-label">%s</span> <strong data-mpcf-stage-next-action>%s</strong></p>',
				esc_html__( 'Next action', 'mp-commerce-fulfillment' ),
				esc_html( (string) $guidance['next_action_label'] )
			);
		}

		echo '</section>';
	}

	/**
	 * Success panel after shipment — not an active unresolved work surface.
	 *
	 * @param Fulfillment $fulfillment Fulfillment being packed.
	 */
	private function render_shipped_success_panel( Fulfillment $fulfillment ): void {
		$is_shipped = in_array( $fulfillment->state(), array( 'shipped', 'delivered', 'completed' ), true );

		printf(
			'<div class="mpcf-workspace__shipped-success"%s data-mpcf-shipped-success>',
			$is_shipped ? '' : ' hidden'
		);

		$tracking = '';
		foreach ( $this->shipping->list_for_fulfillment( (int) $fulfillment->id() ) as $row ) {
			$number = (string) $row['shipment']->tracking()->number();
			if ( '' !== $number ) {
				$tracking = $number;
				break;
			}
		}

		$message = __( 'This fulfillment has been shipped. No further warehouse action is required on this order.', 'mp-commerce-fulfillment' );
		if ( '' !== $tracking ) {
			$message = sprintf(
				/* translators: %s: tracking number */
				__( 'This fulfillment has been shipped. Tracking: %s', 'mp-commerce-fulfillment' ),
				$tracking
			);
		}

		$next_html = '';
		$cursor    = isset( $_GET['cursor'] ) ? sanitize_text_field( wp_unslash( $_GET['cursor'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation cursor.
		if ( '' !== $cursor ) {
			$ids      = array_values( array_filter( array_map( 'absint', explode( ',', $cursor ) ) ) );
			$position = array_search( (int) $fulfillment->id(), $ids, true );
			if ( false !== $position && isset( $ids[ $position + 1 ] ) ) {
				$next_url  = admin_url( 'admin.php?page=' . self::SLUG . '&fulfillment_id=' . (int) $ids[ $position + 1 ] . '&cursor=' . rawurlencode( $cursor ) );
				$next_html = sprintf(
					'<a class="button button-primary" href="%s" data-mpcf-shipped-next-order>%s</a>',
					esc_url( $next_url ),
					esc_html__( 'Next order', 'mp-commerce-fulfillment' )
				);
			}
		}

		if ( '' === $next_html ) {
			$next_html = sprintf(
				'<a class="button button-primary" href="%s" data-mpcf-shipped-next-order>%s</a>',
				esc_url( admin_url( 'admin.php?page=' . QueuePage::SLUG ) ),
				esc_html__( 'Back to Queue', 'mp-commerce-fulfillment' )
			);
		}

		echo $this->renderer->success_panel( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- success_panel() escapes title/message; action HTML is built with esc_url/esc_html above.
			__( 'Shipped', 'mp-commerce-fulfillment' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Passed as method argument; success_panel escapes.
			$message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Passed as method argument; success_panel escapes.
			$next_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_url/esc_html above.
		);

		echo '</div>';
	}

	/**
	 * JSON map of stage guidance for client-side banner refresh after transitions.
	 */
	private function render_stage_guidance_data(): void {
		$payload = array();

		foreach ( $this->definition->states() as $state ) {
			$payload[ $state->key() ] = WorkspaceStageGuidance::for_state( $state->key(), null, $this->definition );
		}

		printf(
			'<script type="application/json" id="mpcf-stage-guidance-data">%s</script>',
			wp_json_encode( $payload ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON in application/json script; not HTML-context.
		);
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
			array( 'Shift + P', __( 'Print primary document (picking list or packing slip)', 'mp-commerce-fulfillment' ) ),
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
	 * flags, and pinned notes. Customer instructions live in the work
	 * region so they sit with the checklist.
	 *
	 * @param FulfillmentDetailView $view  Assembled detail view.
	 * @param OrderSnapshot|null    $order Owning order snapshot, if found.
	 */
	private function render_context_region( FulfillmentDetailView $view, ?OrderSnapshot $order ): void {
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
	 * Renders the customer note when one is present — full
	 * checkout instructions in a warning callout beside the checklist.
	 *
	 * @param string $customer_note Raw note from the owning order.
	 */
	private function render_customer_instructions( string $customer_note ): void {
		$customer_note = trim( $customer_note );

		if ( '' === $customer_note ) {
			return;
		}

		echo '<aside class="mpcf-ui-panel mpcf-ui-panel--warning mpcf-workspace__customer-instructions" role="note" data-mpcf-customer-instructions>';
		echo '<h3 class="mpcf-ui-panel__title">' . esc_html__( 'Customer instructions', 'mp-commerce-fulfillment' ) . '</h3>';
		printf(
			'<p class="mpcf-ui-panel__message mpcf-workspace__customer-note"><span class="mpcf-workspace__customer-note-icon" aria-hidden="true">⚠</span> %s</p>',
			wp_kses( nl2br( esc_html( $customer_note ), false ), array( 'br' => array() ) )
		);
		echo '</aside>';
	}

	/**
	 * Renders the work (centre) region: the stepper, customer instructions,
	 * the item checklist, and the scan sink.
	 *
	 * @param Fulfillment           $fulfillment   Fulfillment being packed.
	 * @param FulfillmentDetailView $view          Assembled detail view.
	 * @param string                $customer_note Checkout note (may be empty).
	 */
	private function render_work_region( Fulfillment $fulfillment, FulfillmentDetailView $view, string $customer_note = '' ): void {
		echo $this->renderer->stepper( $this->build_stepper_steps( $fulfillment->state() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$this->render_customer_instructions( $customer_note );

		$active_field = $this->active_quantity_field( $fulfillment->state() );

		if ( 'queued' === $fulfillment->state() ) {
			echo '<p class="mpcf-workspace__quantity-hint description" data-mpcf-quantity-hint>';
			echo esc_html__( 'Start picking to enable quantity recording. Ordered amounts are shown below for reference.', 'mp-commerce-fulfillment' );
			echo '</p>';
		}

		echo $this->renderer->checklist_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		foreach ( $view->items() as $item ) {
			$qty_ordered = $item->qty_ordered();
			$qty_current = null === $active_field ? 0 : ( 'qty_picked' === $active_field ? $item->qty_picked() : $item->qty_packed() );

			if ( null !== $active_field ) {
				$remaining   = max( 0, $qty_ordered - $qty_current );
				$field_label = 'qty_picked' === $active_field ? esc_html__( 'Picked', 'mp-commerce-fulfillment' ) : esc_html__( 'Packed', 'mp-commerce-fulfillment' );
				$control     = '<div class="mpcf-workspace__item-quantities">';
				$control    .= '<div class="mpcf-workspace__quantity-summary" data-mpcf-quantity-summary>';
				$control    .= sprintf( '<span class="mpcf-workspace__quantity-ordered">%s</span>', esc_html( sprintf( /* translators: %d: ordered qty */ __( 'Ordered: %d', 'mp-commerce-fulfillment' ), $qty_ordered ) ) );
				$control    .= sprintf( '<span class="mpcf-workspace__quantity-processed">%s</span>', esc_html( sprintf( /* translators: 1: Picked or Packed label, 2: current qty */ __( '%1$s: %2$d', 'mp-commerce-fulfillment' ), $field_label, $qty_current ) ) );
				$control    .= sprintf( '<span class="mpcf-workspace__quantity-remaining">%s</span>', esc_html( sprintf( /* translators: %d: remaining qty */ __( 'Remaining: %d', 'mp-commerce-fulfillment' ), $remaining ) ) );
				$control    .= '</div>';
				$control    .= '<div class="mpcf-workspace__quantity-stepper">';
				$control    .= sprintf( '<div class="mpcf-workspace__quantity-display" hidden>%s: %d / %d</div>', $field_label, $qty_current, $qty_ordered );
				$control    .= $this->renderer->quantity_stepper(
					"items[{$item->id()}][{$active_field}]",
					$qty_current,
					0,
					$qty_ordered,
					array(
						'data-mpcf-item-id' => (string) $item->id(),
						/* translators: %s: item name */
						'aria-label'        => sprintf( __( 'Quantity for %s', 'mp-commerce-fulfillment' ), $item->name_snapshot() ),
					)
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer-escaped.
				$control    .= '</div>';
				$control    .= '</div>';
			} elseif ( 'queued' === $fulfillment->state() ) {
				$control = sprintf(
					'<div class="mpcf-workspace__item-quantities mpcf-workspace__item-quantities--inactive" data-mpcf-quantity-summary><span class="mpcf-workspace__quantity-ordered">%s</span><span class="mpcf-workspace__quantity-processed">%s</span><span class="mpcf-workspace__quantity-remaining">%s</span></div>',
					esc_html( sprintf( /* translators: %d: ordered qty */ __( 'Ordered: %d', 'mp-commerce-fulfillment' ), $qty_ordered ) ),
					esc_html( sprintf( /* translators: %d: picked qty */ __( 'Picked: %d', 'mp-commerce-fulfillment' ), $item->qty_picked() ) ),
					esc_html( sprintf( /* translators: %d: remaining */ __( 'Remaining: %d', 'mp-commerce-fulfillment' ), $qty_ordered ) )
				);
			} else {
				$control = sprintf(
					'<div class="mpcf-workspace__item-quantities mpcf-workspace__item-quantities--readonly" data-mpcf-quantity-summary><span>%s</span><span>%s</span></div>',
					esc_html( sprintf( /* translators: 1: picked 2: ordered */ __( 'Picked: %1$d / %2$d', 'mp-commerce-fulfillment' ), $item->qty_picked(), $qty_ordered ) ),
					esc_html( sprintf( /* translators: 1: packed 2: ordered */ __( 'Packed: %1$d / %2$d', 'mp-commerce-fulfillment' ), $item->qty_packed(), $qty_ordered ) )
				);
			}

			$is_complete = null !== $active_field
				? $qty_current >= $qty_ordered
				: ( $item->is_fully_picked() && $item->is_fully_packed() );

			echo $this->renderer->checklist_row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'',
				esc_html( $item->name_snapshot() ),
				sprintf( '<code>%s</code>', esc_html( $item->sku_snapshot() ) ),
				$control, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above from escaped/renderer-escaped pieces only.
				$is_complete, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bool for checklist_row $complete, not markup.
				array( 'data-mpcf-row-id' => (string) $item->id() ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checklist_row escapes attributes.
			);
		}

		echo $this->renderer->checklist_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Always rendered, hidden when there is no active quantity field —
		// never removed and re-added, since `packing.js`'s
		// `refreshChecklist()` just toggles `hidden` on these same nodes
		// after a transition changes the checklist's live/read-only state
		// without a page reload (§IV.5.8 step 2).
		printf(
			'<button type="button" class="button" data-mpcf-complete-all data-mpcf-field="%s"%s>%s</button>',
			esc_attr( (string) $active_field ),
			null === $active_field ? ' hidden' : '',
			esc_html__( 'Complete all', 'mp-commerce-fulfillment' )
		);
		printf(
			'<button type="button" class="button" data-mpcf-toggle-collapse-completed aria-pressed="false"%s>%s</button>',
			null === $active_field ? ' hidden' : '',
			esc_html__( 'Collapse completed', 'mp-commerce-fulfillment' )
		);

		echo $this->renderer->scan_input( 'scan', array( 'aria-label' => __( 'Barcode scanner input', 'mp-commerce-fulfillment' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$this->render_scan_mode_panel( $fulfillment );
	}

	/**
	 * Bounded Scan Mode panel (Part IX.13) — does not replace the checklist.
	 *
	 * @param Fulfillment $fulfillment Current fulfillment.
	 */
	private function render_scan_mode_panel( Fulfillment $fulfillment ): void {
		$state = $fulfillment->state();

		echo '<section class="mpcf-workspace__scan-mode" data-mpcf-scan-mode>';
		echo '<h2 class="mpcf-workspace__scan-mode-title">' . esc_html__( 'Scan Mode', 'mp-commerce-fulfillment' ) . '</h2>';
		echo '<p class="mpcf-workspace__scan-mode-help">' . esc_html__( 'Use a keyboard-wedge barcode scanner, or type a SKU into the scan field and press Enter.', 'mp-commerce-fulfillment' ) . '</p>';
		echo '<div class="mpcf-workspace__scan-mode-actions">';
		printf(
			'<button type="button" class="button button-primary" data-mpcf-scan-mode-enter="picking"%s>%s</button>',
			'picking' === $state ? '' : ' disabled',
			esc_html__( 'Enter Picking Scan Mode', 'mp-commerce-fulfillment' )
		);
		printf(
			'<button type="button" class="button button-primary" data-mpcf-scan-mode-enter="packing"%s>%s</button>',
			'packing' === $state ? '' : ' disabled',
			esc_html__( 'Enter Packing Scan Mode', 'mp-commerce-fulfillment' )
		);
		echo '<button type="button" class="button" data-mpcf-scan-mode-undo hidden>' . esc_html__( 'Undo last scan', 'mp-commerce-fulfillment' ) . '</button>';
		echo '<button type="button" class="button" data-mpcf-scan-mode-exit hidden>' . esc_html__( 'Exit Scan Mode', 'mp-commerce-fulfillment' ) . '</button>';
		echo '<button type="button" class="button" data-mpcf-scan-mode-sound aria-pressed="false" hidden>' . esc_html__( 'Sound off', 'mp-commerce-fulfillment' ) . '</button>';
		echo '</div>';
		echo '<div class="mpcf-workspace__scan-mode-live" data-mpcf-scan-mode-live hidden>';
		echo '<p class="mpcf-workspace__scan-mode-status" data-mpcf-scan-mode-status data-mpcf-scan-mode-status-state="ready" aria-live="polite">' . esc_html__( 'Ready', 'mp-commerce-fulfillment' ) . '</p>';
		echo '<p class="mpcf-workspace__scan-mode-result" data-mpcf-scan-mode-result aria-live="polite"></p>';
		echo '<p class="mpcf-workspace__scan-mode-progress" data-mpcf-scan-mode-progress></p>';
		echo '<h3 class="mpcf-workspace__scan-mode-recent-title">' . esc_html__( 'Recent scans', 'mp-commerce-fulfillment' ) . '</h3>';
		echo '<ul class="mpcf-workspace__scan-mode-recent" data-mpcf-scan-mode-recent></ul>';
		echo '</div>';
		echo '</section>';
	}

	/**
	 * Renders the outcome (right) region: shipment/package panel,
	 * documents, notes, and the recent timeline.
	 *
	 * @param Fulfillment           $fulfillment Fulfillment being packed.
	 * @param FulfillmentDetailView $view        Assembled detail view.
	 */
	private function render_outcome_region( Fulfillment $fulfillment, FulfillmentDetailView $view ): void {
		if ( $this->settings->photos_required() && ( current_user_can( Capabilities::CAPTURE_PHOTOS ) || current_user_can( Capabilities::VIEW_QUEUE ) ) ) {
			printf(
				'<p class="mpcf-workspace__photo-requirement-banner" data-mpcf-photo-requirement-banner aria-live="polite">%s</p>',
				esc_html__( 'A sealed-package photo is required before this fulfillment can be marked packed.', 'mp-commerce-fulfillment' )
			);
		}

		if ( current_user_can( Capabilities::MANAGE_SHIPMENTS ) ) {
			$this->render_shipment_panel( $fulfillment );
		}

		if ( current_user_can( Capabilities::RENDER_DOCUMENTS ) ) {
			$this->render_documents_section( $fulfillment );
		}

		if ( current_user_can( Capabilities::ADD_NOTES ) ) {
			$this->render_notes_section( $view );
		}

		echo '<h3>' . esc_html__( 'Timeline', 'mp-commerce-fulfillment' ) . '</h3>';
		echo $this->renderer->timeline_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// The last five events, fetched with their own bounded query
		// (Architecture Plan §IV.10, risk M2-R11) — not the full chain
		// {@see FulfillmentDetailView::timeline()} still legitimately
		// exposes for hash-chaining/full-history consumers, sliced down to
		// five after the fact.
		foreach ( $this->detail->get_recent_timeline( $fulfillment->id(), 5 ) as $event ) {
			$actor = '' !== (string) $event['actor_label_snapshot'] ? (string) $event['actor_label_snapshot'] : __( 'System', 'mp-commerce-fulfillment' );
			$when  = human_time_diff( strtotime( (string) $event['created_at'] ) ) . ' ' . __( 'ago', 'mp-commerce-fulfillment' );
			$label = DocumentEventLabels::describe( (string) $event['event_type'], (array) ( $event['payload'] ?? array() ) );
			if ( null === $label ) {
				$label = PhotoEventLabels::describe( (string) $event['event_type'], (array) ( $event['payload'] ?? array() ) );
			}
			if ( null === $label ) {
				$label = ScanEventLabels::describe( (string) $event['event_type'], (array) ( $event['payload'] ?? array() ) );
			}
			if ( null === $label ) {
				$label = NotificationEventLabels::describe( (string) $event['event_type'], (array) ( $event['payload'] ?? array() ) );
			}
			$body = null !== $label ? $label : (string) $event['event_type'];

			echo $this->renderer->timeline_item( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'dashicons-clock',
				$actor, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$when, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'<p>' . esc_html( $body ) . '</p>'
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
	 * Bounded Documents action group: picking list + packing slip, stage-gated
	 * via {@see DocumentPrintContext} / {@see \MPCF\Domain\Document\DocumentStagePolicy}.
	 *
	 * @param Fulfillment $fulfillment Fulfillment being worked.
	 */
	private function render_documents_section( Fulfillment $fulfillment ): void {
		echo '<div class="mpcf-workspace__documents" data-mpcf-documents>';
		echo '<h3>' . esc_html__( 'Documents', 'mp-commerce-fulfillment' ) . '</h3>';

		$primary = DocumentPrintContext::primary_doc_type( $fulfillment );

		if ( null === $primary ) {
			echo '<p class="description" data-mpcf-documents-denied>';
			echo esc_html__( 'No printable document is available in this stage.', 'mp-commerce-fulfillment' );
			echo '</p>';
		}

		echo '<div class="mpcf-workspace__document-actions">';

		foreach ( DocumentPrintContext::actions( $fulfillment ) as $action ) {
			$attrs = sprintf(
				'type="button" class="button%s" data-mpcf-print-document="%s"%s%s%s',
				$action['primary'] ? ' button-primary' : '',
				esc_attr( $action['id'] ),
				$action['primary'] ? ' data-mpcf-print-primary' : '',
				$action['allowed'] ? '' : ' disabled',
				$action['allowed'] ? '' : ' title="' . esc_attr( $action['message'] ) . '" data-mpcf-denied-reason="' . esc_attr( $action['message'] ) . '"'
			);

			$label = sprintf(
				/* translators: %s: document type label */
				__( 'Print %s', 'mp-commerce-fulfillment' ),
				$action['label']
			);

			printf( '<button %s>%s</button> ', $attrs, esc_html( $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs built with esc_attr above.

			if ( ! $action['allowed'] && '' !== $action['message'] ) {
				printf(
					'<p class="description mpcf-workspace__document-denied" data-mpcf-doc-denied="%s">%s</p>',
					esc_attr( $action['id'] ),
					esc_html( $action['message'] )
				);
			}
		}

		echo '</div>';

		$this->render_last_printed_status( (int) $fulfillment->id() );

		echo '</div>';
	}

	/**
	 * Latest printed document status per type.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	private function render_last_printed_status( int $fulfillment_id ): void {
		echo '<ul class="mpcf-workspace__document-status" data-mpcf-document-status>';

		foreach ( array( 'picking_list', 'packing_slip' ) as $doc_type ) {
			$latest = $this->documents->latest_for_fulfillment_and_type( $fulfillment_id, $doc_type );
			$label  = DocumentEventLabels::type_label( $doc_type );

			echo '<li data-mpcf-doc-status="' . esc_attr( $doc_type ) . '">';
			echo '<strong>' . esc_html( $label ) . ':</strong> ';

			if ( null === $latest ) {
				echo esc_html__( 'Not printed yet', 'mp-commerce-fulfillment' );
			} else {
				$when = $latest->created_at()->format( 'Y-m-d H:i' );
				$by   = $latest->rendered_by() > 0
					? (string) $latest->rendered_by()
					: __( 'system', 'mp-commerce-fulfillment' );

				printf(
					/* translators: 1: datetime, 2: user id or "system", 3: template version */
					esc_html__( 'Last printed %1$s by %2$s (template %3$s)', 'mp-commerce-fulfillment' ),
					esc_html( $when ),
					esc_html( $by ),
					esc_html( $latest->template_version() )
				);
			}

			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Renders the shipment/package panel: one card per shipment, each with
	 * its own carrier/tracking fields and a package repeater.
	 *
	 * @param Fulfillment $fulfillment Fulfillment being packed.
	 */
	private function render_shipment_panel( Fulfillment $fulfillment ): void {
		$emphasis = WorkspaceStageGuidance::shipment_emphasis( $fulfillment->state() );
		$open     = WorkspaceStageGuidance::shipment_section_open( $fulfillment->state() );

		printf(
			'<details class="mpcf-workspace__shipment-disclosure mpcf-workspace__shipment-disclosure--%s" data-mpcf-shipment-disclosure%s>',
			esc_attr( $emphasis ),
			$open ? ' open' : ''
		);
		echo '<summary class="mpcf-workspace__shipment-summary">';
		echo esc_html__( 'Shipment and packages', 'mp-commerce-fulfillment' );
		if ( 'muted' === $emphasis ) {
			echo ' <span class="description">' . esc_html__( '(later stage)', 'mp-commerce-fulfillment' ) . '</span>';
		}
		echo '</summary>';
		echo '<div class="mpcf-workspace__shipment-body">';

		$rows = $this->shipping->list_for_fulfillment( (int) $fulfillment->id() );

		if ( array() === $rows ) {
			$this->render_new_shipment_card();
		} else {
			foreach ( $rows as $row ) {
				$this->render_shipment_card( $row['shipment'], $row['packages'], $fulfillment );
			}
		}

		echo '</div></details>';
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
		printf( '<select data-mpcf-carrier-select aria-label="%s">', esc_attr__( 'Carrier', 'mp-commerce-fulfillment' ) );
		printf( '<option value="">%s</option>', esc_html__( '— Select —', 'mp-commerce-fulfillment' ) );
		foreach ( $this->carriers->all() as $carrier ) {
			printf( '<option value="%s">%s</option>', esc_attr( $carrier['id'] ), esc_html( $carrier['label'] ) );
		}
		echo '</select>';

		printf(
			'<label>%s</label><input type="text" placeholder="%s" aria-label="%s" data-mpcf-tracking-number>',
			esc_html__( 'Tracking number', 'mp-commerce-fulfillment' ),
			esc_attr__( 'Tracking number', 'mp-commerce-fulfillment' ),
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
	 * @param Shipment            $shipment    Shipment to render.
	 * @param array<int, Package> $packages    Its packages.
	 * @param Fulfillment         $fulfillment Owning fulfillment (photo section).
	 */
	private function render_shipment_card( Shipment $shipment, array $packages, Fulfillment $fulfillment ): void {
		printf(
			'<div class="mpcf-workspace__shipment" data-mpcf-shipment-id="%d" data-mpcf-shipment-service="%s" data-mpcf-shipment-status="%s">',
			(int) $shipment->id(),
			esc_attr( $shipment->service() ),
			esc_attr( $shipment->status() )
		);

		echo '<label>' . esc_html__( 'Carrier', 'mp-commerce-fulfillment' ) . '</label>';
		printf( '<select data-mpcf-carrier-select aria-label="%s">', esc_attr__( 'Carrier', 'mp-commerce-fulfillment' ) );
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
			'<label>%s</label><input type="text" value="%s" aria-label="%s" data-mpcf-tracking-number>',
			esc_html__( 'Tracking number', 'mp-commerce-fulfillment' ),
			esc_attr( (string) $shipment->tracking()->number() ),
			esc_attr__( 'Tracking number', 'mp-commerce-fulfillment' )
		);

		if ( in_array( $shipment->status(), array( Shipment::STATUS_SHIPPED, Shipment::STATUS_DELIVERED ), true ) ) {
			echo '<div class="mpcf-workspace__notification" data-mpcf-notification-panel>';
			echo '<p class="description" data-mpcf-notification-status>' . esc_html__( 'Notification status: loading…', 'mp-commerce-fulfillment' ) . '</p>';
			if ( current_user_can( Capabilities::MANAGE_SHIPMENTS ) ) {
				printf(
					'<button type="button" class="button" data-mpcf-notify-shipment>%s</button>',
					esc_html__( 'Send tracking notification', 'mp-commerce-fulfillment' )
				);
			}
			echo '</div>';
		}

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
			$this->render_package_item( $package, $fulfillment );
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
	 * @param Package     $package     Package to render.
	 * @param Fulfillment $fulfillment Owning fulfillment (photo section).
	 */
	private function render_package_item( Package $package, Fulfillment $fulfillment ): void {
		$spec = $package->spec();
		$id   = (int) $package->id();

		$weight_attrs = array(
			'data-mpcf-package-field' => 'weight_grams',
			'aria-label'              => __( 'Weight', 'mp-commerce-fulfillment' ),
		);
		$length_attrs = array(
			'data-mpcf-package-field' => 'length_mm',
			'aria-label'              => __( 'Length', 'mp-commerce-fulfillment' ),
		);
		$width_attrs  = array(
			'data-mpcf-package-field' => 'width_mm',
			'aria-label'              => __( 'Width', 'mp-commerce-fulfillment' ),
		);
		$height_attrs = array(
			'data-mpcf-package-field' => 'height_mm',
			'aria-label'              => __( 'Height', 'mp-commerce-fulfillment' ),
		);

		$body = $this->renderer->unit_input( "packages[{$id}][weight_grams]", $this->units->grams_to_display( $spec->weight_grams() ), $this->units->weight_unit_label(), $weight_attrs )
			. $this->renderer->unit_input( "packages[{$id}][length_mm]", $this->units->mm_to_display( $spec->length_mm() ), $this->units->dimension_unit_label(), $length_attrs )
			. $this->renderer->unit_input( "packages[{$id}][width_mm]", $this->units->mm_to_display( $spec->width_mm() ), $this->units->dimension_unit_label(), $width_attrs )
			. $this->renderer->unit_input( "packages[{$id}][height_mm]", $this->units->mm_to_display( $spec->height_mm() ), $this->units->dimension_unit_label(), $height_attrs )
			. sprintf(
				'<input type="text" name="packages[%d][tracking_number]" value="%s" placeholder="%s" aria-label="%s" data-mpcf-package-field="tracking_number">',
				$id,
				esc_attr( (string) $package->tracking_number() ),
				esc_attr__( 'Colli tracking number', 'mp-commerce-fulfillment' ),
				esc_attr__( 'Colli tracking number', 'mp-commerce-fulfillment' )
			);

		if ( current_user_can( Capabilities::CAPTURE_PHOTOS ) || current_user_can( Capabilities::VIEW_QUEUE ) ) {
			$body .= $this->render_package_photos( $package, $fulfillment );
		}

		echo $this->renderer->repeater_item( $body, array( 'data-mpcf-package-id' => (string) $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Renders the per-package photo gallery / capture section (M6-C).
	 * Gallery markup is filled by `assets/admin/js/photos.js`.
	 *
	 * @param Package     $package     Package to attach photos to.
	 * @param Fulfillment $fulfillment Owning fulfillment.
	 */
	private function render_package_photos( Package $package, Fulfillment $fulfillment ): string {
		unset( $fulfillment );

		$can_capture = current_user_can( Capabilities::CAPTURE_PHOTOS );
		$can_delete  = current_user_can( Capabilities::DELETE_PHOTOS );
		$required    = $this->settings->photos_required();
		$max         = $this->settings->photos_max_per_fulfillment();

		$capture = '';
		if ( $can_capture ) {
			$capture = sprintf(
				'<div data-mpcf-photo-capture><label>%1$s <select data-mpcf-photo-kind><option value="contents">%2$s</option><option value="package" selected>%3$s</option></select></label><label class="mpcf-workspace__photo-dropzone" data-mpcf-photo-dropzone tabindex="0"><span>%4$s</span><input type="file" accept="image/*" capture="environment" multiple data-mpcf-photo-input></label><div data-mpcf-photo-upload-status aria-live="polite"></div></div>',
				esc_html__( 'Kind', 'mp-commerce-fulfillment' ),
				esc_html__( 'Contents', 'mp-commerce-fulfillment' ),
				esc_html__( 'Sealed package', 'mp-commerce-fulfillment' ),
				esc_html__( 'Drop images here or choose files', 'mp-commerce-fulfillment' )
			);
		}

		return sprintf(
			'<section class="mpcf-workspace__photos" data-mpcf-photos data-mpcf-package-id="%1$d" data-mpcf-can-capture="%2$s" data-mpcf-can-delete="%3$s" data-mpcf-photos-required="%4$s" data-mpcf-photos-max="%5$d"><h4>%6$s</h4><p class="description">%7$s</p><p data-mpcf-photo-requirement-status aria-live="polite"></p><p data-mpcf-photo-count></p><ul data-mpcf-photo-gallery class="mpcf-workspace__photo-gallery"></ul>%8$s</section>',
			(int) $package->id(),
			$can_capture ? '1' : '0',
			$can_delete ? '1' : '0',
			$required ? '1' : '0',
			$max,
			esc_html__( 'Package photos', 'mp-commerce-fulfillment' ),
			esc_html__( 'Contents photos are optional evidence. A Sealed package photo is required when the photo requirement setting is enabled.', 'mp-commerce-fulfillment' ),
			$capture
		);
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
			'<textarea name="new_note" rows="2" placeholder="%s" aria-label="%s" data-mpcf-new-note></textarea>',
			esc_attr__( 'Add a note…', 'mp-commerce-fulfillment' ),
			esc_attr__( 'Add a note', 'mp-commerce-fulfillment' )
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
	 * @param AvailableTransition|null        $primary     Pre-chosen primary candidate.
	 */
	private function render_action_bar( Fulfillment $fulfillment, array $candidates, ?AvailableTransition $primary = null ): void {
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

		if ( null === $primary ) {
			$primary = $this->choose_primary_candidate( $candidates );
		}

		$secondaries = array_filter( $candidates, static fn( $candidate ) => $candidate !== $primary );

		foreach ( $secondaries as $candidate ) {
			if ( ! $candidate->is_approved() ) {
				continue;
			}

			printf(
				'<button type="button" class="button mpcf-workspace__secondary-action" data-mpcf-secondary-action data-mpcf-target="%s"%s>%s</button>',
				esc_attr( $candidate->target() ),
				$candidate->requires_reason() ? ' data-mpcf-requires-reason' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed literal attribute string, not user input.
				esc_html( $candidate->label() )
			);
		}

		if ( null !== $primary ) {
			// This form also contains the reason modal's `required`
			// textarea (rendered further down, still inside the same
			// `<form>` so its own submit bubbles to workspace.js's
			// listener) — while that modal is hidden, native HTML5
			// constraint validation cannot focus it to show the "please
			// fill this in" bubble, so the browser silently blocks *every*
			// submit on this form, including transitions that need no
			// reason at all. Every mutation here already goes through
			// three explicit validation layers of its own (§IV.5.7) —
			// native browser validation was never wanted in the first
			// place, so it is switched off outright rather than patched
			// around per candidate.
			$attrs = array(
				'data-mpcf-target' => $primary->target(),
				'formnovalidate'   => 'formnovalidate',
			);

			if ( $primary->requires_reason() ) {
				$attrs['data-mpcf-requires-reason'] = '1';
			}

			if ( $primary->is_approved() ) {
				echo $this->renderer->action_bar_primary( $primary->label(), $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				$attrs['disabled'] = 'disabled';
				echo $this->renderer->action_bar_primary( $primary->label(), $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				printf(
					'<span class="description mpcf-workspace__guard-message" data-mpcf-guard-message>%s</span>',
					esc_html( WorkspaceStageGuidance::operator_guard_message( $primary->rejection_code(), $primary->rejection_message() ) )
				);
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
				'key'   => $key,
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
