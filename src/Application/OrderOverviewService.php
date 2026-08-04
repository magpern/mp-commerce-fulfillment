<?php
/**
 * Read-side facade for the Orders operational overview.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentQuery;
use MPCF\Domain\OperationalOrderSummary;
use MPCF\Domain\OrderOverviewQuery;
use MPCF\Domain\OrderOverviewResult;
use MPCF\Domain\OrderOverviewRow;
use MPCF\Domain\OrderSource;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Domain\SearchQuery;

/**
 * Combines WooCommerce order summaries with optional MPCF fulfillments.
 * Never creates fulfillments (pending/failed/on-hold/draft stay without
 * rows until intake runs from the normal paid/processing path).
 */
final class OrderOverviewService {

	/**
	 * Warehouse-active fulfillment states.
	 *
	 * @var list<string>
	 */
	private const WAREHOUSE_ACTIVE_STATES = array( 'queued', 'picking', 'picked', 'packing', 'packed' );

	/**
	 * Exception / needs-attention fulfillment states.
	 *
	 * @var list<string>
	 */
	private const NEEDS_ATTENTION_STATES = array( 'problem', 'waiting', 'backordered' );

	/**
	 * Platform order reads.
	 *
	 * @var OrderSource
	 */
	private OrderSource $orders;

	/**
	 * Fulfillment association.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

	/**
	 * Optional SearchQuery for SKU/customer/order-id resolution via fulfillments.
	 *
	 * @var SearchQuery|null
	 */
	private ?SearchQuery $search;

	/**
	 * Builds the service.
	 *
	 * @param OrderSource           $orders       Platform order reads.
	 * @param FulfillmentRepository $fulfillments Fulfillment association.
	 * @param SearchQuery|null      $search       Optional fulfillment search.
	 */
	public function __construct( OrderSource $orders, FulfillmentRepository $fulfillments, ?SearchQuery $search = null ) {
		$this->orders       = $orders;
		$this->fulfillments = $fulfillments;
		$this->search       = $search;
	}

	/**
	 * Lists Orders overview rows for the given filter/search/page.
	 *
	 * @param OrderOverviewQuery $query Filter/page inputs.
	 */
	public function list( OrderOverviewQuery $query ): OrderOverviewResult {
		if ( $query->is_fulfillment_led() ) {
			return $this->list_fulfillment_led( $query );
		}

		return $this->list_order_led( $query );
	}

	/**
	 * Woo-status-driven listing with per-page fulfillment association.
	 *
	 * @param OrderOverviewQuery $query Query.
	 */
	private function list_order_led( OrderOverviewQuery $query ): OrderOverviewResult {
		$statuses  = $this->woo_statuses_for_filter( $query->filter() );
		$result    = $this->orders->list_summaries( $statuses, $query->page(), $query->per_page(), $query->search() );
		$order_ids = array_map( static fn( OperationalOrderSummary $row ): int => $row->order_id(), $result->items() );
		$map       = $this->fulfillments->find_map_by_order_ids( $order_ids );

		$rows = array();

		foreach ( $result->items() as $summary ) {
			$rows[] = $this->to_row( $summary, $map[ $summary->order_id() ] ?? null );
		}

		return new OrderOverviewResult( $rows, $result->total(), $result->page(), $result->per_page() );
	}

	/**
	 * Fulfillment-state-driven listing (warehouse active / needs attention).
	 *
	 * @param OrderOverviewQuery $query Query.
	 */
	private function list_fulfillment_led( OrderOverviewQuery $query ): OrderOverviewResult {
		$states = OrderOverviewQuery::FILTER_NEEDS_ATTENTION === $query->filter()
			? self::NEEDS_ATTENTION_STATES
			: self::WAREHOUSE_ACTIVE_STATES;

		$fulfillment_ids = $this->resolve_search_fulfillment_ids( $query->search() );

		if ( '' !== $query->search() && null !== $fulfillment_ids && array() === $fulfillment_ids ) {
			return new OrderOverviewResult( array(), 0, $query->page(), $query->per_page() );
		}

		$fq = new FulfillmentQuery(
			$states,
			null,
			$fulfillment_ids,
			null,
			'created_at',
			'DESC',
			$query->page(),
			$query->per_page()
		);

		$page        = $this->fulfillments->query( $fq );
		$order_ids   = array_map( static fn( Fulfillment $f ): int => $f->order_id(), $page->items() );
		$summaries   = $this->orders->summaries_by_ids( $order_ids );
		$summary_map = array();

		foreach ( $summaries as $summary ) {
			$summary_map[ $summary->order_id() ] = $summary;
		}

		$rows = array();

		foreach ( $page->items() as $fulfillment ) {
			$summary = $summary_map[ $fulfillment->order_id() ] ?? null;

			if ( null === $summary ) {
				continue;
			}

			$rows[] = $this->to_row( $summary, $fulfillment );
		}

		return new OrderOverviewResult( $rows, $page->total(), $page->page(), $page->per_page() );
	}

	/**
	 * Builds one overview row from a Woo summary and optional fulfillment.
	 *
	 * @param OperationalOrderSummary $summary     Order summary.
	 * @param Fulfillment|null        $fulfillment Associated fulfillment, if any.
	 */
	private function to_row( OperationalOrderSummary $summary, ?Fulfillment $fulfillment ): OrderOverviewRow {
		$state    = null === $fulfillment ? null : $fulfillment->state();
		$guidance = OrdersNextAction::describe( $summary->status(), $state );

		return new OrderOverviewRow(
			$summary->order_id(),
			$summary->order_number(),
			$summary->customer_name(),
			$summary->created_at(),
			$summary->status(),
			$state,
			null === $fulfillment ? null : $fulfillment->id(),
			null === $fulfillment ? null : $fulfillment->assignee_id(),
			$guidance['operational_state'],
			$guidance['next_action'],
			$guidance['open_target']
		);
	}

	/**
	 * WooCommerce status keys for an order-led filter preset.
	 *
	 * @param string $filter Filter key.
	 * @return list<string> Empty list means any status (except trash).
	 */
	private function woo_statuses_for_filter( string $filter ): array {
		switch ( $filter ) {
			case OrderOverviewQuery::FILTER_AWAITING_PAYMENT:
				return array( 'pending', 'on-hold' );
			case OrderOverviewQuery::FILTER_COMPLETED:
				return array( 'completed' );
			case OrderOverviewQuery::FILTER_CANCELLED:
				return array( 'cancelled', 'refunded' );
			default:
				return array();
		}
	}

	/**
	 * Resolves a search term to fulfillment ids when SearchQuery is wired.
	 * Returns null when there is no search term (no restriction).
	 *
	 * @param string $term Search term.
	 * @return list<int>|null
	 */
	private function resolve_search_fulfillment_ids( string $term ): ?array {
		if ( '' === $term ) {
			return null;
		}

		if ( null === $this->search ) {
			return array();
		}

		return $this->search->search( $term );
	}
}
