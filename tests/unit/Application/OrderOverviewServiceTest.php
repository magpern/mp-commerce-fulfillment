<?php
/**
 * Unit tests for OrderOverviewService association and filter strategies.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application;

use DateTimeImmutable;
use MPCF\Application\OrderOverviewService;
use MPCF\Application\OrdersNextAction;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\OperationalOrderListResult;
use MPCF\Domain\OperationalOrderSummary;
use MPCF\Domain\OrderOverviewQuery;
use MPCF\Domain\OrderSource;
use MPCF\Domain\SearchQuery;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentRepository;
use PHPUnit\Framework\TestCase;

/**
 * Uses an in-memory order source + fulfillment repo — no WooCommerce.
 */
final class OrderOverviewServiceTest extends TestCase {

	public function test_order_led_list_associates_fulfillments_without_creating_missing_ones(): void {
		$orders = new class() implements OrderSource {
			public function find( int $order_id ): ?\MPCF\Domain\OrderSnapshot {
				return null;
			}

			public function find_ids_by_status( string $status ): array {
				return array();
			}

			public function list_summaries( array $statuses, int $page, int $per_page, string $search = '' ): OperationalOrderListResult {
				unset( $statuses, $page, $per_page, $search );

				return new OperationalOrderListResult(
					array(
						OperationalOrderSummary::create( 101, '101', 'Ada', 'pending', new DateTimeImmutable( '2026-08-01 10:00:00' ) ),
						OperationalOrderSummary::create( 102, '102', 'Bob', 'processing', new DateTimeImmutable( '2026-08-01 11:00:00' ) ),
					),
					2,
					1,
					20
				);
			}

			public function summaries_by_ids( array $order_ids ): array {
				return array();
			}
		};

		$fulfillments = new InMemoryFulfillmentRepository();
		$id           = $fulfillments->insert(
			Fulfillment::intake( 102, 'woocommerce', 1, 'standard', 'queued', '102', 'Bob', 1, new DateTimeImmutable( '2026-08-01 11:00:00' ) )
		);
		$fulfillment  = $fulfillments->find( (int) $id );
		$fulfillment->apply_transition( 'picking', null, new DateTimeImmutable( '2026-08-01 11:05:00' ) );
		$fulfillments->save( $fulfillment );

		$service = new OrderOverviewService( $orders, $fulfillments );
		$result  = $service->list( new OrderOverviewQuery( OrderOverviewQuery::FILTER_ALL ) );

		self::assertCount( 2, $result->items() );
		self::assertFalse( $result->items()[0]->has_fulfillment(), 'Pending payment must not invent a fulfillment.' );
		self::assertSame( OrdersNextAction::OPEN_WOOCOMMERCE, $result->items()[0]->open_target() );
		self::assertTrue( $result->items()[1]->has_fulfillment() );
		self::assertSame( 'Continue picking', $result->items()[1]->next_action() );
		self::assertSame( OrdersNextAction::OPEN_WORKSPACE, $result->items()[1]->open_target() );
	}

	public function test_warehouse_active_filter_is_fulfillment_led(): void {
		$orders = new class() implements OrderSource {
			public function find( int $order_id ): ?\MPCF\Domain\OrderSnapshot {
				return null;
			}

			public function find_ids_by_status( string $status ): array {
				return array();
			}

			public function list_summaries( array $statuses, int $page, int $per_page, string $search = '' ): OperationalOrderListResult {
				return new OperationalOrderListResult( array(), 0, $page, $per_page );
			}

			public function summaries_by_ids( array $order_ids ): array {
				$out = array();

				foreach ( $order_ids as $id ) {
					$out[] = OperationalOrderSummary::create( (int) $id, (string) $id, 'Op', 'processing', new DateTimeImmutable( '2026-08-01 12:00:00' ) );
				}

				return $out;
			}
		};

		$fulfillments = new InMemoryFulfillmentRepository();
		$id           = $fulfillments->insert(
			Fulfillment::intake( 200, 'woocommerce', 1, 'standard', 'queued', '200', 'Op', 1, new DateTimeImmutable( '2026-08-01 12:00:00' ) )
		);
		$fulfillment  = $fulfillments->find( (int) $id );
		$fulfillment->apply_transition( 'picking', null, new DateTimeImmutable( '2026-08-01 12:05:00' ) );
		$fulfillments->save( $fulfillment );

		$service = new OrderOverviewService( $orders, $fulfillments );
		$result  = $service->list( new OrderOverviewQuery( OrderOverviewQuery::FILTER_WAREHOUSE_ACTIVE ) );

		self::assertCount( 1, $result->items() );
		self::assertSame( 200, $result->items()[0]->order_id() );
		self::assertSame( 'Continue picking', $result->items()[0]->next_action() );
	}

	public function test_search_with_no_fulfillment_matches_returns_empty_for_fulfillment_led_filter(): void {
		$orders = new class() implements OrderSource {
			public function find( int $order_id ): ?\MPCF\Domain\OrderSnapshot {
				return null;
			}

			public function find_ids_by_status( string $status ): array {
				return array();
			}

			public function list_summaries( array $statuses, int $page, int $per_page, string $search = '' ): OperationalOrderListResult {
				return new OperationalOrderListResult( array(), 0, $page, $per_page );
			}

			public function summaries_by_ids( array $order_ids ): array {
				return array();
			}
		};

		$search = new class() implements SearchQuery {
			public function search( string $term ): array {
				unset( $term );

				return array();
			}
		};

		$service = new OrderOverviewService( $orders, new InMemoryFulfillmentRepository(), $search );
		$result  = $service->list( new OrderOverviewQuery( OrderOverviewQuery::FILTER_WAREHOUSE_ACTIVE, 'NOSUCH' ) );

		self::assertSame( 0, $result->total() );
		self::assertSame( array(), $result->items() );
	}
}
