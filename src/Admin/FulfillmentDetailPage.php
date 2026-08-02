<?php
/**
 * The read-oriented Fulfillment Detail admin screen.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

use MPCF\Application\FulfillmentDetailService;
use MPCF\Application\FulfillmentDetailView;
use MPCF\Application\NoteService;
use MPCF\Application\WorkflowService;
use MPCF\Capabilities;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\Workflow\WorkflowDefinition;
use MPCF\Engine\GuardRegistry;
use MPCF\Engine\TransitionContext;
use MPCF\Engine\TransitionResult;
use MPCF\Engine\WorkflowEngine;
use MPCF\Vendor\Mpds\ComponentRenderer;
use MPCF\Vendor\Mpds\PageShell\AdminPageShell;
use MPCF\Vendor\Mpds\PageShell\Page;
use WP_User;

/**
 * "The workspace is for doing; the detail page is for understanding"
 * (Architecture Plan Sec9.3). Transition availability is computed here by
 * consulting {@see WorkflowEngine} directly (pure, WordPress-free) against
 * the same {@see WorkflowDefinition} {@see WorkflowService} uses — never a
 * second, hand-maintained set of UI rules. No raw repository or direct
 * database access here; every read goes through {@see FulfillmentDetailService}, every
 * mutation through {@see NoteService}/{@see WorkflowService} (invariant
 * I11, `AdminBoundaryGuardTest`).
 */
final class FulfillmentDetailPage implements Page {

	/**
	 * This page's slug.
	 */
	public const SLUG = 'mpcf-fulfillment-detail';

	/**
	 * Nonce action for the transition form.
	 */
	private const TRANSITION_NONCE_ACTION = 'mpcf_detail_transition';

	/**
	 * Nonce action for the add-note form.
	 */
	private const NOTE_NONCE_ACTION = 'mpcf_detail_add_note';

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
	 * Read-side fulfillment lookup.
	 *
	 * @var FulfillmentDetailService
	 */
	private FulfillmentDetailService $detail;

	/**
	 * Note mutations.
	 *
	 * @var NoteService
	 */
	private NoteService $notes;

	/**
	 * Transition mutations.
	 *
	 * @var WorkflowService
	 */
	private WorkflowService $workflow;

	/**
	 * The governing workflow.
	 *
	 * @var WorkflowDefinition
	 */
	private WorkflowDefinition $definition;

	/**
	 * Pure transition-eligibility evaluation, for display only.
	 *
	 * @var WorkflowEngine
	 */
	private WorkflowEngine $engine;

	/**
	 * Builds the page.
	 *
	 * @param AdminPageShell           $shell      Page-shell chrome renderer.
	 * @param ComponentRenderer        $renderer   MPDS component renderer.
	 * @param FulfillmentDetailService $detail     Read-side fulfillment lookup.
	 * @param NoteService              $notes      Note mutations.
	 * @param WorkflowService          $workflow   Transition mutations.
	 * @param WorkflowDefinition       $definition The governing workflow.
	 */
	public function __construct(
		AdminPageShell $shell,
		ComponentRenderer $renderer,
		FulfillmentDetailService $detail,
		NoteService $notes,
		WorkflowService $workflow,
		WorkflowDefinition $definition
	) {
		$this->shell      = $shell;
		$this->renderer   = $renderer;
		$this->detail     = $detail;
		$this->notes      = $notes;
		$this->workflow   = $workflow;
		$this->definition = $definition;
		$this->engine     = new WorkflowEngine( GuardRegistry::standard() );
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
		return __( 'Fulfillment Detail', 'mp-commerce-fulfillment' );
	}

	/**
	 * The submenu label.
	 */
	public function menu_title(): string {
		return __( 'Fulfillment Detail', 'mp-commerce-fulfillment' );
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
		$fulfillment_id = isset( $_GET['fulfillment_id'] ) ? (int) $_GET['fulfillment_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, no state change.

		$this->maybe_handle_transition_request( $fulfillment_id );
		$this->maybe_handle_add_note_request( $fulfillment_id );

		$this->shell->open_wrap();
		$this->shell->open();
		$this->render_notice();
		$this->shell->render_header( ShellHeader::view_model( QueuePage::SLUG ) );
		$this->shell->open_content( true );

		if ( $fulfillment_id <= 0 ) {
			$this->shell->open_section_card( __( 'Fulfillment Detail', 'mp-commerce-fulfillment' ) );
			echo $this->renderer->empty_state( 'dashicons-info', __( 'No fulfillment selected', 'mp-commerce-fulfillment' ), __( 'Open a fulfillment from the Queue to see its detail.', 'mp-commerce-fulfillment' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$this->shell->close_section_card();
			$this->shell->close_content();
			$this->shell->close();
			$this->shell->close_wrap();

			return;
		}

		$view = $this->detail->get( $fulfillment_id );

		if ( null === $view ) {
			$this->shell->open_section_card( __( 'Fulfillment Detail', 'mp-commerce-fulfillment' ) );
			echo $this->renderer->empty_state( 'dashicons-warning', __( 'Not found', 'mp-commerce-fulfillment' ), __( 'This fulfillment no longer exists.', 'mp-commerce-fulfillment' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$this->shell->close_section_card();
			$this->shell->close_content();
			$this->shell->close();
			$this->shell->close_wrap();

			return;
		}

		$this->render_summary( $view );
		$this->render_transitions( $view->fulfillment() );
		$this->render_timeline( $view );
		$this->render_notes( $view );

		$this->shell->close_content();
		$this->shell->close();
		$this->shell->close_wrap();
	}

	/**
	 * Renders the order/fulfillment summary card.
	 *
	 * @param FulfillmentDetailView $view Assembled detail view.
	 */
	private function render_summary( FulfillmentDetailView $view ): void {
		$fulfillment = $view->fulfillment();
		$state       = $this->definition->has_state( $fulfillment->state() ) ? $this->definition->state( $fulfillment->state() ) : null;

		$this->shell->open_section_card( sprintf( '%s %s', __( 'Fulfillment for order', 'mp-commerce-fulfillment' ), $fulfillment->order_number_snapshot() ) );

		printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Customer:', 'mp-commerce-fulfillment' ), esc_html( $fulfillment->customer_name_snapshot() ) );
		printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Items:', 'mp-commerce-fulfillment' ), esc_html( (string) $fulfillment->item_count() ) );

		if ( null !== $state ) {
			printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'State:', 'mp-commerce-fulfillment' ), $this->renderer->status_badge( $state->label(), $state->badge_variant() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- A real order-platform capability (shop_manager/administrator only), not a typo; deliberately not one of this plugin's own mpcf_* capabilities.
			$order_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $fulfillment->order_id() );
			printf( '<p><a href="%s">%s</a></p>', esc_url( $order_url ), esc_html__( 'View order', 'mp-commerce-fulfillment' ) );
		}

		$this->shell->close_section_card();
	}

	/**
	 * Renders every transition {@see WorkflowEngine} approves or rejects for
	 * this fulfillment's current state, plus the dynamic exception-
	 * resolution edge when applicable — never a hand-maintained UI list.
	 *
	 * @param Fulfillment $fulfillment Fulfillment being detailed.
	 */
	private function render_transitions( Fulfillment $fulfillment ): void {
		$this->shell->open_section_card( __( 'Transitions', 'mp-commerce-fulfillment' ) );

		$context = new TransitionContext( array(), true, true, true );
		$targets = array_map( static fn( $transition ) => $transition->to(), $this->definition->transitions_from( $fulfillment->state() ) );

		if ( $this->definition->has_state( $fulfillment->state() ) && $this->definition->state( $fulfillment->state() )->is_exception() && null !== $fulfillment->return_to_state() ) {
			$targets[] = $fulfillment->return_to_state();
		}

		$rendered = 0;

		foreach ( array_unique( $targets ) as $target ) {
			$transition = $this->definition->transition( $fulfillment->state(), $target );
			$capability = null !== $transition ? $transition->required_capability() : Capabilities::PROCESS_FULFILLMENTS;

			if ( ! current_user_can( $capability ) ) {
				continue;
			}

			$result = $this->engine->transition( $fulfillment, $target, $this->definition, $context );
			$label  = $this->definition->has_state( $target ) ? $this->definition->state( $target )->label() : $target;

			$this->render_transition_control( $target, $label, $result, null !== $transition && $transition->requires_reason() );
			++$rendered;
		}

		if ( 0 === $rendered ) {
			echo '<p>' . esc_html__( 'No transitions are available.', 'mp-commerce-fulfillment' ) . '</p>';
		}

		$this->shell->close_section_card();
	}

	/**
	 * Renders one transition's control: a plain submit button when
	 * approved and reason-less, a reason-capture modal trigger when
	 * approved and reason-required, or disabled text with the guard's
	 * rejection reason when the engine rejects it.
	 *
	 * @param string           $target         Candidate target state.
	 * @param string           $label          Target state's display label.
	 * @param TransitionResult $result         The engine's decision for this attempt.
	 * @param bool             $requires_reason Whether this edge requires an audited reason.
	 */
	private function render_transition_control( string $target, string $label, TransitionResult $result, bool $requires_reason ): void {
		if ( ! $result->is_approved() ) {
			printf(
				'<p><button type="button" class="button" disabled aria-disabled="true">%s</button> <span class="description">%s</span></p>',
				esc_html( $label ),
				esc_html( (string) $result->rejection_message() )
			);

			return;
		}

		if ( $requires_reason ) {
			printf( '<button type="button" class="button" data-mpcf-modal-open="mpcf-reason-%s">%s</button> ', esc_attr( $target ), esc_html( $label ) );

			return;
		}

		printf( '<form method="post" style="display:inline">' );
		wp_nonce_field( self::TRANSITION_NONCE_ACTION );
		printf( '<input type="hidden" name="mpcf_transition_target" value="%s">', esc_attr( $target ) );
		printf( '<button type="submit" class="button">%s</button>', esc_html( $label ) );
		echo '</form> ';
	}

	/**
	 * Renders the reason-capture modals for every reason-required
	 * transition — collected separately from their trigger buttons, the
	 * same "render once, trigger anywhere" pattern the Queue's row drawers
	 * use.
	 *
	 * @param Fulfillment $fulfillment Fulfillment being detailed.
	 */
	private function render_reason_modals( Fulfillment $fulfillment ): void {
		$context = new TransitionContext( array(), true, true, true );

		foreach ( $this->definition->transitions_from( $fulfillment->state() ) as $transition ) {
			if ( ! $transition->requires_reason() ) {
				continue;
			}

			if ( ! current_user_can( $transition->required_capability() ) ) {
				continue;
			}

			$result = $this->engine->transition( $fulfillment, $transition->to(), $this->definition, $context );

			if ( ! $result->is_approved() ) {
				continue;
			}

			$label = $this->definition->has_state( $transition->to() ) ? $this->definition->state( $transition->to() )->label() : $transition->to();

			printf( '<form method="post">' );
			wp_nonce_field( self::TRANSITION_NONCE_ACTION );
			printf( '<input type="hidden" name="mpcf_transition_target" value="%s">', esc_attr( $transition->to() ) );
			echo $this->renderer->reason_modal( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- reason_modal() escapes every arg internally; see per-line notes below.
				'mpcf-reason-' . $transition->to(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A workflow state key, never user input; reason_modal() escapes it internally as an element id.
				$label, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A workflow state's own display label; reason_modal() escapes it internally.
				'reason',
				sprintf(
					/* translators: %s: target state label */
					esc_html__( 'Why is this fulfillment moving to "%s"?', 'mp-commerce-fulfillment' ),
					esc_html( $label )
				),
				$label // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Same label as above.
			);
			echo '</form>';
		}
	}

	/**
	 * Renders the full audit timeline.
	 *
	 * @param FulfillmentDetailView $view Assembled detail view.
	 */
	private function render_timeline( FulfillmentDetailView $view ): void {
		$this->shell->open_section_card( __( 'Audit trail', 'mp-commerce-fulfillment' ) );

		echo $this->renderer->timeline_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		foreach ( $view->timeline() as $event ) {
			$actor = '' !== (string) $event['actor_label_snapshot'] ? (string) $event['actor_label_snapshot'] : __( 'System', 'mp-commerce-fulfillment' );
			$when  = human_time_diff( strtotime( (string) $event['created_at'] ) ) . ' ' . __( 'ago', 'mp-commerce-fulfillment' );

			echo $this->renderer->timeline_item( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- timeline_item() escapes every arg internally; see per-line notes below.
				'dashicons-clock',
				$actor, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- timeline_item() escapes the actor/time args internally.
				$when, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- timeline_item() escapes the actor/time args internally.
				'<p>' . esc_html( $this->describe_event( (string) $event['event_type'], (array) $event['payload'] ) ) . '</p>'
			);
		}

		echo $this->renderer->timeline_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$this->shell->close_section_card();

		$this->render_reason_modals( $view->fulfillment() );
	}

	/**
	 * A short, human-readable description of one audit event.
	 *
	 * @param string               $event_type Event type identifier.
	 * @param array<string, mixed> $payload    Event payload.
	 */
	private function describe_event( string $event_type, array $payload ): string {
		if ( 'fulfillment.created' === $event_type ) {
			return __( 'Fulfillment created from order.', 'mp-commerce-fulfillment' );
		}

		if ( 'fulfillment.state_changed' === $event_type ) {
			$reason = isset( $payload['reason'] ) ? ' — ' . (string) $payload['reason'] : '';

			return sprintf(
				/* translators: 1: origin state, 2: destination state, 3: optional reason */
				__( 'Moved from "%1$s" to "%2$s".%3$s', 'mp-commerce-fulfillment' ),
				(string) ( $payload['from'] ?? '?' ),
				(string) ( $payload['to'] ?? '?' ),
				$reason
			);
		}

		return $event_type;
	}

	/**
	 * Renders notes (pinned first) and the add-note form.
	 *
	 * @param FulfillmentDetailView $view Assembled detail view.
	 */
	private function render_notes( FulfillmentDetailView $view ): void {
		if ( ! current_user_can( Capabilities::ADD_NOTES ) ) {
			return;
		}

		$this->shell->open_section_card( __( 'Notes', 'mp-commerce-fulfillment' ) );

		foreach ( $view->notes() as $note ) {
			$author = get_userdata( $note->author_id() );
			$pinned = $note->is_pinned() ? ' <strong>(' . esc_html__( 'pinned', 'mp-commerce-fulfillment' ) . ')</strong>' : '';

			printf(
				'<p>%s %s — %s</p>',
				esc_html( $author instanceof WP_User ? $author->display_name : __( 'Unknown', 'mp-commerce-fulfillment' ) ),
				wp_kses_post( $pinned ),
				esc_html( $note->body() )
			);
		}

		printf( '<form method="post">' );
		wp_nonce_field( self::NOTE_NONCE_ACTION );
		printf( '<textarea name="body" rows="3" required></textarea>' );
		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Add note', 'mp-commerce-fulfillment' ) );
		echo '</form>';

		$this->shell->close_section_card();
	}

	/**
	 * Attempts one transition. Public and decoupled from `$_POST`/redirect
	 * handling so it is directly testable — `wp_safe_redirect()` + `exit`
	 * in a real request would otherwise make this logic untestable from
	 * PHPUnit, the same reasoning behind `Cli\BackfillCommand::run_backfill()`
	 * and `QueuePage::handle_bulk_action()`.
	 *
	 * @param int         $fulfillment_id Fulfillment being transitioned.
	 * @param string      $target         Target state.
	 * @param string|null $reason         Reason text, if the edge requires one.
	 * @return string|null Null on success, a human-readable error otherwise.
	 */
	public function submit_transition( int $fulfillment_id, string $target, ?string $reason ): ?string {
		$view = $this->detail->get( $fulfillment_id );

		if ( null === $view ) {
			return __( 'Fulfillment not found.', 'mp-commerce-fulfillment' );
		}

		$transition = $this->definition->transition( $view->fulfillment()->state(), $target );
		$capability = null !== $transition ? $transition->required_capability() : Capabilities::PROCESS_FULFILLMENTS;

		if ( ! current_user_can( $capability ) ) {
			return __( 'You are not allowed to make this change.', 'mp-commerce-fulfillment' );
		}

		$outcome = $this->workflow->transition( $fulfillment_id, $target, self::current_actor(), $reason, true, true );

		return $outcome->is_success() ? null : (string) $outcome->failure_message();
	}

	/**
	 * Adds one note. Public for the same testability reason as
	 * {@see submit_transition()}.
	 *
	 * @param int    $fulfillment_id Fulfillment the note belongs to.
	 * @param string $body           Note text.
	 * @return string|null Null on success, a human-readable error otherwise.
	 */
	public function apply_note( int $fulfillment_id, string $body ): ?string {
		if ( ! current_user_can( Capabilities::ADD_NOTES ) ) {
			return __( 'You are not allowed to add notes.', 'mp-commerce-fulfillment' );
		}

		if ( '' === $body ) {
			return __( 'A note cannot be empty.', 'mp-commerce-fulfillment' );
		}

		$this->notes->add( $fulfillment_id, get_current_user_id(), $body );

		return null;
	}

	/**
	 * Processes a submitted transition form.
	 *
	 * @param int $fulfillment_id Fulfillment being transitioned.
	 */
	private function maybe_handle_transition_request( int $fulfillment_id ): void {
		if ( ! isset( $_POST['mpcf_transition_target'] ) || $fulfillment_id <= 0 ) {
			return;
		}

		check_admin_referer( self::TRANSITION_NONCE_ACTION );

		$target = sanitize_key( wp_unslash( $_POST['mpcf_transition_target'] ) );
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : null;

		$error = $this->submit_transition( $fulfillment_id, $target, $reason );

		if ( null !== $error ) {
			set_transient( self::notice_transient_key(), $error, MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&fulfillment_id=' . $fulfillment_id ) );
		exit;
	}

	/**
	 * Processes a submitted add-note form.
	 *
	 * @param int $fulfillment_id Fulfillment the note belongs to.
	 */
	private function maybe_handle_add_note_request( int $fulfillment_id ): void {
		if ( ! isset( $_POST['body'] ) || $fulfillment_id <= 0 ) {
			return;
		}

		check_admin_referer( self::NOTE_NONCE_ACTION );

		$body  = sanitize_textarea_field( wp_unslash( $_POST['body'] ) );
		$error = $this->apply_note( $fulfillment_id, $body );

		if ( null !== $error ) {
			set_transient( self::notice_transient_key(), $error, MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&fulfillment_id=' . $fulfillment_id ) );
		exit;
	}

	/**
	 * Renders the previous request's transition-failure notice, if any —
	 * including an optimistic-lock conflict, as a standard WordPress admin
	 * notice.
	 */
	private function render_notice(): void {
		$message = get_transient( self::notice_transient_key() );

		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}

		delete_transient( self::notice_transient_key() );

		printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * The current user as an {@see Actor}.
	 */
	private static function current_actor(): Actor {
		$user = wp_get_current_user();

		return Actor::user( $user->ID, $user->display_name );
	}

	/**
	 * The per-user transient key the transition-failure notice is stashed under.
	 */
	private static function notice_transient_key(): string {
		return 'mpcf_detail_notice_' . get_current_user_id();
	}
}
