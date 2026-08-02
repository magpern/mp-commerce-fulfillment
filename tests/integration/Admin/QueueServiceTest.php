<?php
/**
 * Integration tests for the Queue's filter/search listing, against a real
 * database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Admin;

use DateTimeImmutable;
use MPCF\Application\QueueService;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentQuery;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\Database\WpdbSearchQuery;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use WP_UnitTestCase;

/**
 * Real-database proof that SearchQuery v1's classification (numeric/SKU/
 * name) and FulfillmentQuery's state/assignee filters produce the correct
 * rows via indexed lookups — not just that the in-memory unit doubles agree
 * with themselves.
 */
final class QueueServiceTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;

	/**
	 * @var WpdbFulfillmentRepository
	 */
	private WpdbFulfillmentRepository $fulfillments;

	/**
	 * @var QueueService
	 */
	private QueueService $queue;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();

		$this->fulfillments = new WpdbFulfillmentRepository();
		$this->queue        = new QueueService( $this->fulfillments, new WpdbSearchQuery() );
	}

	private function seed( int $order_id, string $state, string $customer, string $sku ): int {
		$fulfillment = Fulfillment::intake( $order_id, 'woocommerce', 1, 'standard', 'queued', '#' . $order_id, $customer, 1, new DateTimeImmutable() );
		$id          = $this->fulfillments->insert( $fulfillment );

		if ( 'queued' !== $state ) {
			$stored = $this->fulfillments->find( $id );
			$stored->apply_transition( $state, null, new DateTimeImmutable() );
			$this->fulfillments->save( $stored );
		}

		( new WpdbFulfillmentItemRepository() )->insert_all(
			array( \MPCF\Domain\FulfillmentItem::intake( $id, 501, 900, 0, $sku, 'Widget', 1 ) )
		);

		return $id;
	}

	public function test_default_open_filter_excludes_completed_fulfillments(): void {
		$open      = $this->seed( 1001, 'queued', 'Jane Doe', 'SKU-1' );
		$completed = $this->seed( 1002, 'queued', 'John Doe', 'SKU-2' );

		$stored = $this->fulfillments->find( $completed );
		$stored->apply_transition( 'completed', null, new DateTimeImmutable() );
		$this->fulfillments->save( $stored );

		$result = $this->queue->list( new FulfillmentQuery( array( 'queued' ) ) );
		$ids    = array_map( static fn( Fulfillment $f ): int => $f->id(), $result->items() );

		self::assertContains( $open, $ids );
		self::assertNotContains( $completed, $ids );
	}

	public function test_numeric_search_finds_the_fulfillment_by_order_id(): void {
		$id = $this->seed( 2001, 'queued', 'Jane Doe', 'SKU-1' );
		$this->seed( 2002, 'queued', 'John Doe', 'SKU-2' );

		$result = $this->queue->list( new FulfillmentQuery(), '2001' );

		self::assertCount( 1, $result->items() );
		self::assertSame( $id, $result->items()[0]->id() );
	}

	public function test_sku_shaped_search_finds_the_fulfillment_by_item_sku(): void {
		$id = $this->seed( 3001, 'queued', 'Jane Doe', 'WIDGET-9' );
		$this->seed( 3002, 'queued', 'John Doe', 'GADGET-1' );

		// "WIDGET-9" (not the bare word "WIDGET") is what makes
		// SearchTermClassifier actually classify this as SKU-shaped — it
		// requires both a letter and a digit, matching common SKU
		// conventions rather than a plain customer-name word.
		$result = $this->queue->list( new FulfillmentQuery(), 'WIDGET-9' );

		self::assertCount( 1, $result->items() );
		self::assertSame( $id, $result->items()[0]->id() );
	}

	public function test_name_search_finds_the_fulfillment_by_customer_name(): void {
		$id = $this->seed( 4001, 'queued', 'Zsazsa Zwolinski', 'SKU-1' );
		$this->seed( 4002, 'queued', 'Someone Else', 'SKU-2' );

		$result = $this->queue->list( new FulfillmentQuery(), 'Zsazsa' );

		self::assertCount( 1, $result->items() );
		self::assertSame( $id, $result->items()[0]->id() );
	}

	public function test_assignee_filter_matches_only_the_assigned_fulfillment(): void {
		$assigned   = $this->seed( 5001, 'queued', 'Jane Doe', 'SKU-1' );
		$unassigned = $this->seed( 5002, 'queued', 'John Doe', 'SKU-2' );

		$fulfillment = $this->fulfillments->find( $assigned );
		$fulfillment->assign( 'user', 7 );
		$this->fulfillments->save( $fulfillment );

		$result = $this->queue->list( new FulfillmentQuery( array(), 7 ) );
		$ids    = array_map( static fn( Fulfillment $f ): int => $f->id(), $result->items() );

		self::assertContains( $assigned, $ids );
		self::assertNotContains( $unassigned, $ids );
	}

	public function test_unassigned_sentinel_matches_only_unassigned_fulfillments(): void {
		$assigned   = $this->seed( 6001, 'queued', 'Jane Doe', 'SKU-1' );
		$unassigned = $this->seed( 6002, 'queued', 'John Doe', 'SKU-2' );

		$fulfillment = $this->fulfillments->find( $assigned );
		$fulfillment->assign( 'user', 7 );
		$this->fulfillments->save( $fulfillment );

		$result = $this->queue->list( new FulfillmentQuery( array(), FulfillmentQuery::SENTINEL_UNASSIGNED ) );
		$ids    = array_map( static fn( Fulfillment $f ): int => $f->id(), $result->items() );

		self::assertContains( $unassigned, $ids );
		self::assertNotContains( $assigned, $ids );
	}
}
