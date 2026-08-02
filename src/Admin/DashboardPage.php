<?php
/**
 * The operational Dashboard admin screen.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

use MPCF\Application\DashboardService;
use MPCF\Capabilities;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\Workflow\WorkflowDefinition;
use MPCF\Vendor\Mpds\ComponentRenderer;
use MPCF\Vendor\Mpds\PageShell\AdminPageShell;
use MPCF\Vendor\Mpds\PageShell\Page;

/**
 * Architecture Plan Sec9.3: an operational workspace answering "what needs
 * attention now", never an analytics page — no historical charts, trend
 * graphs, operator productivity metrics, or picking-list actions. No quick-
 * actions panel: M1 has no valid quick action (document rendering is M3),
 * and an empty or disabled panel is explicitly worse than none.
 */
final class DashboardPage implements Page {

	/**
	 * This page's slug.
	 */
	public const SLUG = 'mpcf-dashboard';

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
	 * Read-side dashboard queries.
	 *
	 * @var DashboardService
	 */
	private DashboardService $dashboard;

	/**
	 * The governing workflow.
	 *
	 * @var WorkflowDefinition
	 */
	private WorkflowDefinition $definition;

	/**
	 * Builds the page.
	 *
	 * @param AdminPageShell     $shell      Page-shell chrome renderer.
	 * @param ComponentRenderer  $renderer   MPDS component renderer.
	 * @param DashboardService   $dashboard  Read-side dashboard queries.
	 * @param WorkflowDefinition $definition The governing workflow.
	 */
	public function __construct( AdminPageShell $shell, ComponentRenderer $renderer, DashboardService $dashboard, WorkflowDefinition $definition ) {
		$this->shell      = $shell;
		$this->renderer   = $renderer;
		$this->dashboard  = $dashboard;
		$this->definition = $definition;
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
		return __( 'Fulfillment Dashboard', 'mp-commerce-fulfillment' );
	}

	/**
	 * The submenu label.
	 */
	public function menu_title(): string {
		return __( 'Dashboard', 'mp-commerce-fulfillment' );
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
		$this->shell->open_wrap();
		$this->shell->open();
		$this->shell->render_header( ShellHeader::view_model( self::SLUG ) );
		$this->shell->open_content( true );

		$this->render_needs_attention();
		$this->render_stats();

		$this->shell->close_content();
		$this->shell->close();
		$this->shell->close_wrap();
	}

	/**
	 * Renders the next-actions band: problem/waiting oldest first, oldest
	 * open, unassigned.
	 */
	private function render_needs_attention(): void {
		$this->render_list(
			__( 'Needs attention', 'mp-commerce-fulfillment' ),
			$this->dashboard->needs_attention( $this->definition ),
			admin_url( 'admin.php?page=' . QueuePage::SLUG . '&state=exception' ),
			__( 'No fulfillments currently need attention.', 'mp-commerce-fulfillment' )
		);

		$this->render_list(
			__( 'Oldest open', 'mp-commerce-fulfillment' ),
			$this->dashboard->oldest_open( $this->definition ),
			admin_url( 'admin.php?page=' . QueuePage::SLUG . '&state=open' ),
			__( 'No open fulfillments.', 'mp-commerce-fulfillment' )
		);

		$this->render_list(
			__( 'Unassigned', 'mp-commerce-fulfillment' ),
			$this->dashboard->unassigned( $this->definition ),
			admin_url( 'admin.php?page=' . QueuePage::SLUG . '&state=open&assignee=unassigned' ),
			__( 'Everything open is assigned.', 'mp-commerce-fulfillment' )
		);
	}

	/**
	 * Renders one next-actions list card.
	 *
	 * @param string                  $title        Card title.
	 * @param array<int, Fulfillment> $fulfillments Rows to list.
	 * @param string                  $see_all_url  URL for the "see all" link, filtered appropriately.
	 * @param string                  $empty_message Message shown when there are no rows.
	 */
	private function render_list( string $title, array $fulfillments, string $see_all_url, string $empty_message ): void {
		$this->shell->open_section_card( $title );

		if ( array() === $fulfillments ) {
			echo $this->renderer->empty_state( 'dashicons-yes', __( 'All clear', 'mp-commerce-fulfillment' ), $empty_message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$this->shell->close_section_card();

			return;
		}

		echo '<ul class="mpcf-dashboard-list">';

		foreach ( $fulfillments as $fulfillment ) {
			$detail_url = admin_url( 'admin.php?page=' . FulfillmentDetailPage::SLUG . '&fulfillment_id=' . $fulfillment->id() );
			$age        = human_time_diff( $fulfillment->state_entered_at()->getTimestamp() );

			printf(
				'<li><a href="%s">%s</a> — %s (%s)</li>',
				esc_url( $detail_url ),
				esc_html( $fulfillment->order_number_snapshot() ),
				esc_html( $fulfillment->customer_name_snapshot() ),
				esc_html( $age )
			);
		}

		echo '</ul>';

		printf( '<p><a href="%s">%s</a></p>', esc_url( $see_all_url ), esc_html__( 'See all in Queue', 'mp-commerce-fulfillment' ) );

		$this->shell->close_section_card();
	}

	/**
	 * Renders today's operational stat cards.
	 */
	private function render_stats(): void {
		$this->shell->open_section_card( __( 'Today', 'mp-commerce-fulfillment' ) );

		echo $this->renderer->statistics_grid_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->statistics_card( __( 'Open', 'mp-commerce-fulfillment' ), (string) $this->dashboard->open_count( $this->definition ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->statistics_card( __( 'In exception', 'mp-commerce-fulfillment' ), (string) $this->dashboard->exception_count( $this->definition ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->statistics_card( __( 'Packed today', 'mp-commerce-fulfillment' ), (string) $this->dashboard->packed_today() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->statistics_card( __( 'Shipped today', 'mp-commerce-fulfillment' ), (string) $this->dashboard->shipped_today() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->statistics_grid_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$this->shell->close_section_card();
	}
}
