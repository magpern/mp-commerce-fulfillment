<?php
/**
 * The Orders operational overview admin screen.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

use MPCF\Application\OrderOverviewService;
use MPCF\Application\OrdersNextAction;
use MPCF\Capabilities;
use MPCF\Domain\OrderOverviewQuery;
use MPCF\Domain\OrderOverviewResult;
use MPCF\Domain\OrderOverviewRow;
use MPCF\Vendor\Mpds\ComponentRenderer;
use MPCF\Vendor\Mpds\PageShell\AdminPageShell;
use MPCF\Vendor\Mpds\PageShell\Page;

/**
 * Read-only "Where is my order?" overview. Combines WooCommerce order
 * status with optional MPCF fulfillment state. Never creates fulfillments
 * and never mutates WooCommerce or workflow state.
 */
final class OrdersPage implements Page {

	/**
	 * This page's slug.
	 */
	public const SLUG = 'mpcf-orders';

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
	 * Read-side Orders listing.
	 *
	 * @var OrderOverviewService
	 */
	private OrderOverviewService $orders;

	/**
	 * Builds the page.
	 *
	 * @param AdminPageShell       $shell    Page-shell chrome renderer.
	 * @param ComponentRenderer    $renderer MPDS component renderer.
	 * @param OrderOverviewService $orders   Read-side Orders listing.
	 */
	public function __construct( AdminPageShell $shell, ComponentRenderer $renderer, OrderOverviewService $orders ) {
		$this->shell    = $shell;
		$this->renderer = $renderer;
		$this->orders   = $orders;
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
		return __( 'Orders', 'mp-commerce-fulfillment' );
	}

	/**
	 * The submenu label.
	 */
	public function menu_title(): string {
		return __( 'Orders', 'mp-commerce-fulfillment' );
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
		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : OrderOverviewQuery::FILTER_ALL; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter, no state change.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$query  = new OrderOverviewQuery( $filter, $search, $page, 20 );
		$result = $this->orders->list( $query );

		$this->shell->open_wrap();
		$this->shell->open();
		$this->shell->render_header( ShellHeader::view_model( self::SLUG ) );
		$this->shell->open_content( true );
		$this->shell->open_section_card( __( 'Orders', 'mp-commerce-fulfillment' ), __( 'Operational overview of store orders. WooCommerce remains the system of record for payment and order lifecycle.', 'mp-commerce-fulfillment' ), 'dashicons-clipboard' );

		$this->render_filter_bar( $query->filter(), $query->search() );
		$this->render_table( $result );
		$this->render_pagination( $result, $query->filter(), $query->search() );

		$this->shell->close_section_card();
		$this->shell->close_content();
		$this->shell->close();
		$this->shell->close_wrap();
	}

	/**
	 * Renders the lightweight filter bar.
	 *
	 * @param string $filter Current filter key.
	 * @param string $search Current search term.
	 */
	private function render_filter_bar( string $filter, string $search ): void {
		printf( '<form method="get" action="%s">', esc_url( admin_url( 'admin.php' ) ) );
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::SLUG ) );

		echo $this->renderer->filter_bar_open( array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->filter_bar_field( __( 'View', 'mp-commerce-fulfillment' ), $this->filter_control( $filter ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->filter_bar_search( 's', $search, __( 'Order #, customer, or SKU…', 'mp-commerce-fulfillment' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->filter_bar_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</form>';
	}

	/**
	 * Builds the filter `<select>` control.
	 *
	 * @param string $current Current filter key.
	 */
	private function filter_control( string $current ): string {
		$options = array(
			OrderOverviewQuery::FILTER_ALL              => __( 'All', 'mp-commerce-fulfillment' ),
			OrderOverviewQuery::FILTER_NEEDS_ATTENTION  => __( 'Needs Attention', 'mp-commerce-fulfillment' ),
			OrderOverviewQuery::FILTER_WAREHOUSE_ACTIVE => __( 'Warehouse Active', 'mp-commerce-fulfillment' ),
			OrderOverviewQuery::FILTER_AWAITING_PAYMENT => __( 'Awaiting Payment', 'mp-commerce-fulfillment' ),
			OrderOverviewQuery::FILTER_COMPLETED        => __( 'Completed', 'mp-commerce-fulfillment' ),
			OrderOverviewQuery::FILTER_CANCELLED        => __( 'Cancelled', 'mp-commerce-fulfillment' ),
		);

		$html = '<select name="filter" onchange="this.form.submit()">';

		foreach ( $options as $value => $label ) {
			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		$html .= '</select>';

		return $html;
	}

	/**
	 * Renders the Orders data table.
	 *
	 * @param OrderOverviewResult $result Listing result.
	 */
	private function render_table( OrderOverviewResult $result ): void {
		echo $this->renderer->data_table_open( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				array( 'label' => esc_html__( 'Order', 'mp-commerce-fulfillment' ) ),
				array( 'label' => esc_html__( 'Customer', 'mp-commerce-fulfillment' ) ),
				array( 'label' => esc_html__( 'Date', 'mp-commerce-fulfillment' ) ),
				array( 'label' => esc_html__( 'WooCommerce', 'mp-commerce-fulfillment' ) ),
				array( 'label' => esc_html__( 'Fulfillment', 'mp-commerce-fulfillment' ) ),
				array( 'label' => esc_html__( 'Assignee', 'mp-commerce-fulfillment' ) ),
				array( 'label' => esc_html__( 'Operational state', 'mp-commerce-fulfillment' ) ),
				array( 'label' => esc_html__( 'Next action', 'mp-commerce-fulfillment' ) ),
				array( 'label' => esc_html__( 'Open', 'mp-commerce-fulfillment' ) ),
			),
			array( 'aria-label' => esc_attr__( 'Orders', 'mp-commerce-fulfillment' ) )
		);

		foreach ( $result->items() as $row ) {
			$this->render_row( $row );
		}

		if ( array() === $result->items() ) {
			echo $this->renderer->data_table_empty_row( 9, __( 'No orders match these filters.', 'mp-commerce-fulfillment' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo $this->renderer->data_table_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo $this->renderer->kbd_hints_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->kbd_hint( 'j/k', __( 'Navigate', 'mp-commerce-fulfillment' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->kbd_hint( 'Enter', __( 'Open', 'mp-commerce-fulfillment' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->kbd_hint( '/', __( 'Search', 'mp-commerce-fulfillment' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->renderer->kbd_hints_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Renders one Orders row.
	 *
	 * @param OrderOverviewRow $row Overview row.
	 */
	private function render_row( OrderOverviewRow $row ): void {
		$open  = $this->open_link( $row );
		$badge = $this->renderer->status_badge( $this->woo_status_label( $row->woo_status() ), 'neutral' );

		echo $this->renderer->data_table_row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				array(
					'html' => sprintf(
						'<a href="%s" data-mpcf-row-open><strong>%s</strong></a>',
						esc_url( $open['url'] ),
						esc_html( $row->order_number() )
					),
				),
				array( 'html' => esc_html( $row->customer_name() ) ),
				array( 'html' => esc_html( $row->order_date()->format( get_option( 'date_format' ) ) ) ),
				array( 'html' => $badge ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ComponentRenderer::status_badge() returns escaped markup.
				array( 'html' => esc_html( $this->fulfillment_status_label( $row ) ) ),
				array( 'html' => esc_html( $this->assignee_label( $row->assignee_id() ) ) ),
				array( 'html' => esc_html( $row->operational_state() ) ),
				array( 'html' => esc_html( $row->next_action() ) ),
				array(
					'html' => sprintf(
						'<a class="button button-small" href="%s" data-mpcf-row-open>%s</a>',
						esc_url( $open['url'] ),
						esc_html( $open['label'] )
					),
				),
			),
			array( 'data-mpcf-row-id' => esc_attr( (string) $row->order_id() ) )
		);
	}

	/**
	 * Open destination URL and button label for one row.
	 *
	 * @param OrderOverviewRow $row Overview row.
	 * @return array{url: string, label: string}
	 */
	private function open_link( OrderOverviewRow $row ): array {
		if ( OrdersNextAction::OPEN_WORKSPACE === $row->open_target() && null !== $row->fulfillment_id() ) {
			return array(
				'url'   => admin_url( 'admin.php?page=' . WorkspacePage::SLUG . '&fulfillment_id=' . $row->fulfillment_id() ),
				'label' => __( 'Open Workspace', 'mp-commerce-fulfillment' ),
			);
		}

		return array(
			'url'   => admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $row->order_id() ),
			'label' => __( 'Open order', 'mp-commerce-fulfillment' ),
		);
	}

	/**
	 * WooCommerce status display label.
	 *
	 * @param string $status WC status key.
	 */
	private function woo_status_label( string $status ): string {
		if ( function_exists( 'wc_get_order_status_name' ) ) {
			return (string) wc_get_order_status_name( $status );
		}

		return $status;
	}

	/**
	 * Fulfillment column label, or an em dash when none exists.
	 *
	 * @param OrderOverviewRow $row Overview row.
	 */
	private function fulfillment_status_label( OrderOverviewRow $row ): string {
		if ( ! $row->has_fulfillment() ) {
			return '—';
		}

		$state = (string) $row->fulfillment_state();

		$labels = array(
			'queued'      => __( 'Queued', 'mp-commerce-fulfillment' ),
			'picking'     => __( 'Picking', 'mp-commerce-fulfillment' ),
			'picked'      => __( 'Picked', 'mp-commerce-fulfillment' ),
			'packing'     => __( 'Packing', 'mp-commerce-fulfillment' ),
			'packed'      => __( 'Packed', 'mp-commerce-fulfillment' ),
			'shipped'     => __( 'Shipped', 'mp-commerce-fulfillment' ),
			'delivered'   => __( 'Delivered', 'mp-commerce-fulfillment' ),
			'completed'   => __( 'Completed', 'mp-commerce-fulfillment' ),
			'problem'     => __( 'Problem', 'mp-commerce-fulfillment' ),
			'waiting'     => __( 'Waiting', 'mp-commerce-fulfillment' ),
			'backordered' => __( 'Backordered', 'mp-commerce-fulfillment' ),
			'cancelled'   => __( 'Cancelled', 'mp-commerce-fulfillment' ),
		);

		return $labels[ $state ] ?? $state;
	}

	/**
	 * Assignee display name, or an em dash when unassigned / no fulfillment.
	 *
	 * @param int|null $user_id Assignee user id.
	 */
	private function assignee_label( ?int $user_id ): string {
		if ( null === $user_id || $user_id <= 0 ) {
			return '—';
		}

		$user = get_userdata( $user_id );

		return $user instanceof \WP_User ? $user->display_name : sprintf( '#%d', $user_id );
	}

	/**
	 * Renders pagination links.
	 *
	 * @param OrderOverviewResult $result Listing result.
	 * @param string              $filter Current filter.
	 * @param string              $search Current search.
	 */
	private function render_pagination( OrderOverviewResult $result, string $filter, string $search ): void {
		if ( $result->total_pages() <= 1 ) {
			return;
		}

		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core helper returns escaped markup.
			array(
				'base'      => add_query_arg(
					array(
						'page'   => self::SLUG,
						'filter' => $filter,
						's'      => $search,
						'paged'  => '%#%',
					),
					admin_url( 'admin.php' )
				),
				'format'    => '',
				'current'   => $result->page(),
				'total'     => $result->total_pages(),
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			)
		);
		echo '</div></div>';
	}
}
