<?php
/**
 * The Fulfillment Queue admin screen.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

use MPCF\Application\AssignmentService;
use MPCF\Application\FulfillmentDetailService;
use MPCF\Application\QueueService;
use MPCF\Application\WorkflowService;
use MPCF\Capabilities;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentQuery;
use MPCF\Domain\Workflow\WorkflowDefinition;
use MPCF\Vendor\Mpds\ComponentRenderer;
use MPCF\Vendor\Mpds\PageShell\AdminPageShell;
use MPCF\Vendor\Mpds\PageShell\Page;

/**
 * Architecture Plan Sec9.3: the operational hub. Server-side pagination,
 * indexed-only queries (via {@see QueueService}), guard-checked bulk
 * actions with per-row partial-failure reporting. No raw repository or
 * direct database access here — every read goes through {@see QueueService}/
 * {@see FulfillmentDetailService}, every mutation through
 * {@see AssignmentService}/{@see WorkflowService} (invariant I11,
 * `AdminBoundaryGuardTest`).
 */
final class QueuePage implements Page {

	/**
	 * This page's slug.
	 */
	public const SLUG = 'mpcf-queue';

	/**
	 * Nonce action for the bulk-action form.
	 */
	private const BULK_NONCE_ACTION = 'mpcf_queue_bulk_action';

	/**
	 * Age filter options, label => seconds.
	 *
	 * @var array<string, int>
	 */
	private const AGE_OPTIONS = array(
		'1h'  => HOUR_IN_SECONDS,
		'4h'  => 4 * HOUR_IN_SECONDS,
		'24h' => DAY_IN_SECONDS,
		'3d'  => 3 * DAY_IN_SECONDS,
	);

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
	 * Read-side Queue listing.
	 *
	 * @var QueueService
	 */
	private QueueService $queue;

	/**
	 * Read-side fulfillment lookup, for per-row capability checks.
	 *
	 * @var FulfillmentDetailService
	 */
	private FulfillmentDetailService $detail;

	/**
	 * Assignment mutations.
	 *
	 * @var AssignmentService
	 */
	private AssignmentService $assignments;

	/**
	 * Transition mutations.
	 *
	 * @var WorkflowService
	 */
	private WorkflowService $workflow;

	/**
	 * The governing workflow, for state labels and edge lookups.
	 *
	 * @var WorkflowDefinition
	 */
	private WorkflowDefinition $definition;

	/**
	 * Builds the page.
	 *
	 * @param AdminPageShell           $shell       Page-shell chrome renderer.
	 * @param ComponentRenderer        $renderer    MPDS component renderer.
	 * @param QueueService             $queue       Read-side Queue listing.
	 * @param FulfillmentDetailService $detail      Read-side fulfillment lookup, for per-row capability checks.
	 * @param AssignmentService        $assignments Assignment mutations.
	 * @param WorkflowService          $workflow    Transition mutations.
	 * @param WorkflowDefinition       $definition  The governing workflow, for state labels and edge lookups.
	 */
	public function __construct(
		AdminPageShell $shell,
		ComponentRenderer $renderer,
		QueueService $queue,
		FulfillmentDetailService $detail,
		AssignmentService $assignments,
		WorkflowService $workflow,
		WorkflowDefinition $definition
	) {
		$this->shell       = $shell;
		$this->renderer    = $renderer;
		$this->queue       = $queue;
		$this->detail      = $detail;
		$this->assignments = $assignments;
		$this->workflow    = $workflow;
		$this->definition  = $definition;
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
		return __( 'Fulfillment Queue', 'mp-commerce-fulfillment' );
	}

	/**
	 * The submenu label.
	 */
	public function menu_title(): string {
		return __( 'Queue', 'mp-commerce-fulfillment' );
	}

	/**
	 * The capability required to view this page.
	 */
	public function capability(): string {
		return Capabilities::VIEW_QUEUE;
	}

	/**
	 * Renders the page body.
	 */
	public function render(): void {
		$this->maybe_handle_bulk_action_request();

		$state_filter    = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : 'open'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter, no state change.
		$assignee_filter = isset( $_GET['assignee'] ) ? sanitize_text_field( wp_unslash( $_GET['assignee'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$age_filter      = isset( $_GET['age'] ) ? sanitize_key( wp_unslash( $_GET['age'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search_term     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page            = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$query = new FulfillmentQuery(
			$this->states_for_filter( $state_filter ),
			$this->assignee_for_filter( $assignee_filter ),
			null,
			self::AGE_OPTIONS[ $age_filter ] ?? null,
			'created_at',
			'DESC',
			$page,
			20
		);

		$result = $this->queue->list( $query, $search_term );

		$this->shell->open_wrap();
		$this->shell->open();
		$this->render_notice();
		$this->shell->render_header( ShellHeader::view_model( self::SLUG ) );
		$this->shell->open_content( true );
		$this->shell->open_section_card( __( 'Fulfillments', 'mp-commerce-fulfillment' ), '', 'dashicons-list-view' );

		$this->render_filter_bar( $state_filter, $assignee_filter, $age_filter, $search_term );
		$this->render_table( $result->items() );
		$this->render_pagination( $result, $state_filter, $assignee_filter, $age_filter, $search_term );

		$this->shell->close_section_card();
		$this->shell->close_content();
		$this->shell->close();
		$this->shell->close_wrap();
	}

	/**
	 * Runs a bulk assign/advance action and reports per-row outcomes.
	 * Public and decoupled from `$_POST`/`$_GET` so it is directly
	 * testable, the same way `Cli\BackfillCommand::run_backfill()` is.
	 *
	 * @param array<int, int>      $ids    Fulfillment ids to act on.
	 * @param string               $action `assign` or `advance`.
	 * @param array<string, mixed> $params Action-specific parameters (`assignee_id` or `target_state`).
	 * @return array{succeeded: list<int>, failed: array<int, string>}
	 */
	public function handle_bulk_action( array $ids, string $action, array $params ): array {
		$result = array(
			'succeeded' => array(),
			'failed'    => array(),
		);

		foreach ( $ids as $id ) {
			$error = 'assign' === $action
				? $this->apply_assign( $id, $params )
				: $this->apply_advance( $id, $params );

			if ( null === $error ) {
				$result['succeeded'][] = $id;
			} else {
				$result['failed'][ $id ] = $error;
			}
		}

		return $result;
	}

	/**
	 * Attempts a bulk "assign" action for one row.
	 *
	 * @param int                  $id     Fulfillment id.
	 * @param array<string, mixed> $params Action parameters.
	 */
	private function apply_assign( int $id, array $params ): ?string {
		if ( ! current_user_can( Capabilities::PROCESS_FULFILLMENTS ) ) {
			return __( 'You are not allowed to assign fulfillments.', 'mp-commerce-fulfillment' );
		}

		$assignee_id = (int) ( $params['assignee_id'] ?? 0 );

		if ( $assignee_id <= 0 ) {
			return __( 'No assignee was selected.', 'mp-commerce-fulfillment' );
		}

		return $this->assignments->assign( $id, $assignee_id, self::current_actor() )
			? null
			: __( 'Could not assign — it may have been changed by someone else.', 'mp-commerce-fulfillment' );
	}

	/**
	 * Attempts a bulk "advance" action for one row. Looks up the specific
	 * capability the row's actual current-state-to-target edge requires
	 * before attempting it — the edges bulk rows are in can differ, so this
	 * must be checked per row, never once for the whole request.
	 *
	 * @param int                  $id     Fulfillment id.
	 * @param array<string, mixed> $params Action parameters.
	 */
	private function apply_advance( int $id, array $params ): ?string {
		$target = sanitize_key( (string) ( $params['target_state'] ?? '' ) );
		$view   = $this->detail->get( $id );

		if ( null === $view ) {
			return __( 'Fulfillment not found.', 'mp-commerce-fulfillment' );
		}

		$transition = $this->definition->transition( $view->fulfillment()->state(), $target );
		$capability = null !== $transition ? $transition->required_capability() : Capabilities::PROCESS_FULFILLMENTS;

		if ( ! current_user_can( $capability ) ) {
			return __( 'You are not allowed to make this change.', 'mp-commerce-fulfillment' );
		}

		$outcome = $this->workflow->transition( $id, $target, self::current_actor() );

		return $outcome->is_success() ? null : (string) $outcome->failure_message();
	}

	/**
	 * Processes a submitted bulk-action form, then redirects back to the
	 * Queue (POST-redirect-GET) with the outcome stashed for one display.
	 */
	private function maybe_handle_bulk_action_request(): void {
		if ( ! isset( $_POST['mpcf_bulk_action'] ) ) {
			return;
		}

		check_admin_referer( self::BULK_NONCE_ACTION );

		$action = sanitize_key( wp_unslash( $_POST['mpcf_bulk_action'] ) );
		$ids    = array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) );
		$params = array(
			'assignee_id'  => isset( $_POST['assignee_id'] ) ? (int) $_POST['assignee_id'] : 0,
			'target_state' => isset( $_POST['target_state'] ) ? sanitize_key( wp_unslash( $_POST['target_state'] ) ) : '',
		);

		$result = $this->handle_bulk_action( $ids, $action, $params );

		set_transient( self::notice_transient_key(), $result, MINUTE_IN_SECONDS );

		$referer  = wp_get_referer();
		$redirect = remove_query_arg( array( '_wpnonce', '_wp_http_referer' ), false !== $referer ? $referer : admin_url( 'admin.php?page=' . self::SLUG ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Renders the previous request's bulk-action outcome, if any.
	 */
	private function render_notice(): void {
		$result = get_transient( self::notice_transient_key() );

		if ( ! is_array( $result ) ) {
			return;
		}

		delete_transient( self::notice_transient_key() );

		$succeeded = count( $result['succeeded'] ?? array() );
		$failed    = $result['failed'] ?? array();

		if ( array() === $failed ) {
			/* translators: %d: number of fulfillments updated */
			$message = sprintf( _n( '%d fulfillment updated.', '%d fulfillments updated.', $succeeded, 'mp-commerce-fulfillment' ), $succeeded );
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );

			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: succeeded count, 2: failed count */
					__( '%1$d fulfillment(s) updated; %2$d could not be updated.', 'mp-commerce-fulfillment' ),
					$succeeded,
					count( $failed )
				)
			)
		);
	}

	/**
	 * Renders the filter bar (a GET form, separate from the bulk-action
	 * POST form the table below is wrapped in).
	 *
	 * @param string $state_filter    Current state filter value.
	 * @param string $assignee_filter Current assignee filter value.
	 * @param string $age_filter      Current age filter value.
	 * @param string $search_term     Current search term.
	 */
	private function render_filter_bar( string $state_filter, string $assignee_filter, string $age_filter, string $search_term ): void {
		printf( '<form method="get" action="%s">', esc_url( admin_url( 'admin.php' ) ) );
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::SLUG ) );

		echo $this->renderer->filter_bar_open( array( 'aria-label' => __( 'Queue filters', 'mp-commerce-fulfillment' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ComponentRenderer output is pre-escaped.
		echo $this->renderer->filter_bar_field( __( 'State', 'mp-commerce-fulfillment' ), $this->state_filter_control( $state_filter ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->filter_bar_field( __( 'Assignee', 'mp-commerce-fulfillment' ), $this->assignee_filter_control( $assignee_filter ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->filter_bar_field( __( 'Age', 'mp-commerce-fulfillment' ), $this->age_filter_control( $age_filter ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->filter_bar_search( 's', $search_term, __( 'Search order #, customer, or SKU…', 'mp-commerce-fulfillment' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Filter', 'mp-commerce-fulfillment' ) );
		echo $this->renderer->filter_bar_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</form>';
	}

	/**
	 * Builds the state filter `<select>` control.
	 *
	 * @param string $current Current filter value.
	 */
	private function state_filter_control( string $current ): string {
		$options = array(
			'open' => __( 'Open', 'mp-commerce-fulfillment' ),
			'all'  => __( 'All', 'mp-commerce-fulfillment' ),
		);
		foreach ( $this->definition->states() as $state ) {
			$options[ $state->key() ] = $state->label();
		}

		$html = '<select name="state">';
		foreach ( $options as $value => $label ) {
			$html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		$html .= '</select>';

		return $html;
	}

	/**
	 * Builds the assignee filter `<select>` control, listing every user who
	 * can process fulfillments.
	 *
	 * @param string $current Current filter value.
	 */
	private function assignee_filter_control( string $current ): string {
		$html  = '<select name="assignee">';
		$html .= sprintf( '<option value=""%s>%s</option>', selected( $current, '', false ), esc_html__( 'Any', 'mp-commerce-fulfillment' ) );
		$html .= sprintf( '<option value="unassigned"%s>%s</option>', selected( $current, 'unassigned', false ), esc_html__( 'Unassigned', 'mp-commerce-fulfillment' ) );

		foreach ( get_users( array( 'capability' => Capabilities::PROCESS_FULFILLMENTS ) ) as $user ) {
			$html .= sprintf( '<option value="%d"%s>%s</option>', $user->ID, selected( $current, (string) $user->ID, false ), esc_html( $user->display_name ) );
		}

		$html .= '</select>';

		return $html;
	}

	/**
	 * Builds the age filter `<select>` control.
	 *
	 * @param string $current Current filter value.
	 */
	private function age_filter_control( string $current ): string {
		$html  = '<select name="age">';
		$html .= sprintf( '<option value=""%s>%s</option>', selected( $current, '', false ), esc_html__( 'Any age', 'mp-commerce-fulfillment' ) );

		foreach ( array_keys( self::AGE_OPTIONS ) as $key ) {
			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $current, $key, false ),
				/* translators: %s: age threshold, e.g. "4h" */
				esc_html( sprintf( __( 'Older than %s', 'mp-commerce-fulfillment' ), $key ) )
			);
		}

		$html .= '</select>';

		return $html;
	}

	/**
	 * Renders the bulk-action POST form wrapping the data table and one
	 * drawer per row.
	 *
	 * @param array<int, Fulfillment> $fulfillments This page's rows.
	 */
	private function render_table( array $fulfillments ): void {
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) );
		wp_nonce_field( self::BULK_NONCE_ACTION );

		$this->render_bulk_action_controls();

		echo $this->renderer->data_table_open( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				array(
					'label'    => '<input type="checkbox" data-mpcf-select-all aria-label="' . esc_attr__( 'Select all', 'mp-commerce-fulfillment' ) . '">',
					'checkbox' => true,
				),
				array( 'label' => esc_html__( 'Order', 'mp-commerce-fulfillment' ) ),
				array( 'label' => esc_html__( 'Customer', 'mp-commerce-fulfillment' ) ),
				array( 'label' => esc_html__( 'Items', 'mp-commerce-fulfillment' ) ),
				array( 'label' => esc_html__( 'State', 'mp-commerce-fulfillment' ) ),
				array( 'label' => esc_html__( 'Age', 'mp-commerce-fulfillment' ) ),
				array( 'label' => esc_html__( 'Priority', 'mp-commerce-fulfillment' ) ),
				array( 'label' => esc_html__( 'Assignee', 'mp-commerce-fulfillment' ) ),
			),
			array( 'aria-label' => esc_attr__( 'Fulfillments', 'mp-commerce-fulfillment' ) )
		);

		// The opaque queue cursor (§IV.5.3) `WorkspacePage` reads back to
		// render its Previous/Next links and to know what "Next order"
		// means after a ship — this page's own current-page slice, since
		// that is what `j`/`k` already lets an operator browse without a
		// second query for the full filtered set across every page.
		$cursor = implode( ',', array_map( static fn( Fulfillment $fulfillment ): string => (string) $fulfillment->id(), $fulfillments ) );

		foreach ( $fulfillments as $fulfillment ) {
			$this->render_row( $fulfillment, $cursor );
		}

		if ( array() === $fulfillments ) {
			echo $this->renderer->data_table_empty_row( 8, __( 'No fulfillments match these filters.', 'mp-commerce-fulfillment' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo $this->renderer->data_table_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</form>';

		echo $this->renderer->kbd_hints_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->kbd_hint( 'j/k', __( 'Navigate', 'mp-commerce-fulfillment' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->kbd_hint( 'Enter', __( 'Open in Workspace', 'mp-commerce-fulfillment' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->kbd_hint( '/', __( 'Search', 'mp-commerce-fulfillment' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->kbd_hints_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		foreach ( $fulfillments as $fulfillment ) {
			$this->render_drawer( $fulfillment, $cursor );
		}
	}

	/**
	 * The Packing Workspace URL for one fulfillment, carrying the current
	 * queue slice forward as the opaque cursor `WorkspacePage` reads back
	 * (Architecture Plan §IV.5.1/§IV.5.3).
	 *
	 * @param int    $fulfillment_id Target fulfillment.
	 * @param string $cursor         Comma-separated id list for the current queue slice.
	 */
	private function workspace_url( int $fulfillment_id, string $cursor ): string {
		return admin_url( 'admin.php?page=' . WorkspacePage::SLUG . '&fulfillment_id=' . $fulfillment_id . '&cursor=' . rawurlencode( $cursor ) );
	}

	/**
	 * Renders the bulk-action selector and its context-sensitive controls.
	 */
	private function render_bulk_action_controls(): void {
		echo '<p class="mpcf-queue-bulk-actions">';
		echo '<select name="mpcf_bulk_action">';
		printf( '<option value="">%s</option>', esc_html__( 'Bulk actions…', 'mp-commerce-fulfillment' ) );
		printf( '<option value="assign">%s</option>', esc_html__( 'Assign to…', 'mp-commerce-fulfillment' ) );
		printf( '<option value="advance">%s</option>', esc_html__( 'Advance to state…', 'mp-commerce-fulfillment' ) );
		echo '</select>';

		echo '<select name="assignee_id">';
		foreach ( get_users( array( 'capability' => Capabilities::PROCESS_FULFILLMENTS ) ) as $user ) {
			printf( '<option value="%d">%s</option>', (int) $user->ID, esc_html( $user->display_name ) );
		}
		echo '</select>';

		echo '<select name="target_state">';
		foreach ( $this->definition->states() as $state ) {
			if ( $state->is_initial() ) {
				continue;
			}

			printf( '<option value="%s">%s</option>', esc_attr( $state->key() ), esc_html( $state->label() ) );
		}
		echo '</select>';

		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Apply', 'mp-commerce-fulfillment' ) );
		echo '</p>';
	}

	/**
	 * Renders one Queue row. The order-number cell is a real anchor
	 * straight to the Packing Workspace — `data-table-keynav.js`'s `Enter`
	 * clicks whatever `[data-mpcf-row-open]` marks, and a real `<a href>`
	 * is what makes that click (and a plain mouse click) navigate directly
	 * rather than needing a JS-only intermediate step, and what makes
	 * middle-click/`Ctrl`+click open a second monitor's worth of workspace
	 * (§IV.5.1/§IV.5.6). The separate preview button next to it still
	 * opens the row's drawer for a non-committal look without navigating
	 * away — the drawer is not removed, only no longer the thing `Enter`
	 * reaches.
	 *
	 * @param Fulfillment $fulfillment Row to render.
	 * @param string      $cursor      The current queue slice, for the workspace link.
	 */
	private function render_row( Fulfillment $fulfillment, string $cursor ): void {
		$state     = $this->definition->has_state( $fulfillment->state() ) ? $this->definition->state( $fulfillment->state() ) : null;
		$badge     = null !== $state
			? $this->renderer->status_badge( $state->label(), $state->badge_variant() )
			: esc_html( $fulfillment->state() );
		$assignee  = null !== $fulfillment->assignee_id() ? $this->assignee_label( $fulfillment->assignee_id() ) : esc_html__( 'Unassigned', 'mp-commerce-fulfillment' );
		$drawer_id = 'mpcf-drawer-' . $fulfillment->id();
		$age       = human_time_diff( $fulfillment->state_entered_at()->getTimestamp() );

		$identity_cell = sprintf(
			'<a href="%s" data-mpcf-row-open>%s</a> <button type="button" class="button-link" data-mpcf-drawer-open="%s" aria-label="%s">%s</button>',
			esc_url( $this->workspace_url( (int) $fulfillment->id(), $cursor ) ),
			esc_html( $fulfillment->order_number_snapshot() ),
			esc_attr( $drawer_id ),
			esc_attr__( 'Preview', 'mp-commerce-fulfillment' ),
			esc_html__( 'Preview', 'mp-commerce-fulfillment' )
		);

		echo $this->renderer->data_table_row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				array(
					'html'     => '<input type="checkbox" name="ids[]" value="' . esc_attr( (string) $fulfillment->id() ) . '">',
					'checkbox' => true,
				),
				array( 'html' => $identity_cell ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above from esc_url()/esc_html()/esc_attr()-escaped pieces only.
				array( 'html' => esc_html( $fulfillment->customer_name_snapshot() ) ),
				array(
					'html'    => esc_html( (string) $fulfillment->item_count() ),
					'numeric' => true,
				),
				array( 'html' => $badge ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $badge is either ComponentRenderer::status_badge()'s pre-escaped output or esc_html() output, per the ternary above.
				array( 'html' => esc_html( $age ) ),
				array(
					'html'    => esc_html( (string) $fulfillment->priority() ),
					'numeric' => true,
				),
				array( 'html' => esc_html( $assignee ) ),
			),
			array( 'data-mpcf-row-id' => esc_attr( (string) $fulfillment->id() ) )
		);
	}

	/**
	 * Renders one row's preview drawer. Its primary action is the Packing
	 * Workspace (§IV.5.1's second named entry point) — Fulfillment Detail
	 * stays reachable as a secondary link for the full audit trail.
	 *
	 * @param Fulfillment $fulfillment Fulfillment the drawer previews.
	 * @param string      $cursor      The current queue slice, for the workspace link.
	 */
	private function render_drawer( Fulfillment $fulfillment, string $cursor ): void {
		$drawer_id     = 'mpcf-drawer-' . $fulfillment->id();
		$workspace_url = $this->workspace_url( (int) $fulfillment->id(), $cursor );
		$detail_url    = admin_url( 'admin.php?page=' . FulfillmentDetailPage::SLUG . '&fulfillment_id=' . $fulfillment->id() );

		echo $this->renderer->drawer_open( $drawer_id, $fulfillment->order_number_snapshot() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<p>%s: %s</p>', esc_html__( 'Customer', 'mp-commerce-fulfillment' ), esc_html( $fulfillment->customer_name_snapshot() ) );
		printf( '<p>%s: %s</p>', esc_html__( 'Items', 'mp-commerce-fulfillment' ), esc_html( (string) $fulfillment->item_count() ) );
		printf( '<p>%s: %s</p>', esc_html__( 'State', 'mp-commerce-fulfillment' ), esc_html( $fulfillment->state() ) );
		echo $this->renderer->drawer_footer( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'<a class="button button-primary" href="' . esc_url( $workspace_url ) . '">' . esc_html__( 'Open in Workspace', 'mp-commerce-fulfillment' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built entirely from esc_url()/esc_html__() output.
			. ' <a class="button" href="' . esc_url( $detail_url ) . '">' . esc_html__( 'Fulfillment Detail', 'mp-commerce-fulfillment' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built entirely from esc_url()/esc_html__() output.
		);
	}

	/**
	 * Renders simple prev/next pagination links, preserving filters.
	 *
	 * @param \MPCF\Domain\FulfillmentQueryResult $result          Current page result.
	 * @param string                              $state_filter    Current state filter value.
	 * @param string                              $assignee_filter Current assignee filter value.
	 * @param string                              $age_filter      Current age filter value.
	 * @param string                              $search_term     Current search term.
	 */
	private function render_pagination( \MPCF\Domain\FulfillmentQueryResult $result, string $state_filter, string $assignee_filter, string $age_filter, string $search_term ): void {
		if ( $result->total_pages() <= 1 ) {
			return;
		}

		echo '<nav class="mpcf-queue-pagination" aria-label="' . esc_attr__( 'Queue pagination', 'mp-commerce-fulfillment' ) . '">';

		$total_pages = $result->total_pages();

		for ( $page_number = 1; $page_number <= $total_pages; $page_number++ ) {
			$url = add_query_arg(
				array(
					'page'     => self::SLUG,
					'state'    => $state_filter,
					'assignee' => $assignee_filter,
					'age'      => $age_filter,
					's'        => $search_term,
					'paged'    => $page_number,
				),
				admin_url( 'admin.php' )
			);

			if ( $page_number === $result->page() ) {
				printf( '<span aria-current="page">%d</span> ', (int) $page_number );
			} else {
				printf( '<a href="%s">%d</a> ', esc_url( $url ), (int) $page_number );
			}
		}

		echo '</nav>';
	}

	/**
	 * Translates the state filter value into a list of state keys.
	 *
	 * @param string $filter Filter value: `open`, `all`, or a specific state key.
	 * @return list<string>
	 */
	private function states_for_filter( string $filter ): array {
		if ( 'all' === $filter ) {
			return array();
		}

		if ( 'open' === $filter ) {
			return array_values(
				array_map(
					static fn( $state ) => $state->key(),
					array_filter( $this->definition->states(), static fn( $state ) => $state->counts_as_open() )
				)
			);
		}

		if ( 'exception' === $filter ) {
			// Linked from the Dashboard's needs-attention band, which groups
			// every exception state together (problem/waiting/backordered),
			// not just one — the plain per-state option below can only ever
			// select one specific key.
			return array_values(
				array_map(
					static fn( $state ) => $state->key(),
					array_filter( $this->definition->states(), static fn( $state ) => $state->is_exception() )
				)
			);
		}

		return $this->definition->has_state( $filter ) ? array( $filter ) : array();
	}

	/**
	 * Translates the assignee filter value into a `FulfillmentQuery` filter.
	 *
	 * @param string $filter Filter value: empty, `unassigned`, or a numeric user id.
	 * @return int|string|null
	 */
	private function assignee_for_filter( string $filter ) {
		if ( 'unassigned' === $filter ) {
			return FulfillmentQuery::SENTINEL_UNASSIGNED;
		}

		return ctype_digit( $filter ) ? (int) $filter : null;
	}

	/**
	 * A user's display name, for the assignee column.
	 *
	 * @param int $user_id User id.
	 */
	private function assignee_label( int $user_id ): string {
		$user = get_userdata( $user_id );

		return $user instanceof \WP_User ? $user->display_name : sprintf( '#%d', $user_id );
	}

	/**
	 * The current user as an {@see Actor}.
	 */
	private static function current_actor(): Actor {
		$user = wp_get_current_user();

		return Actor::user( $user->ID, $user->display_name );
	}

	/**
	 * The per-user transient key the bulk-action notice is stashed under.
	 */
	private static function notice_transient_key(): string {
		return 'mpcf_queue_bulk_result_' . get_current_user_id();
	}
}
