<?php
/**
 * In-memory test double for the order source port.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Doubles;

use MPCF\Domain\OrderSnapshot;
use MPCF\Domain\OrderSource;

/**
 * A fixed set of orders seeded by the test, standing in for
 * {@see \MPCF\Woo\WooOrderSource} without any platform involved.
 */
final class InMemoryOrderSource implements OrderSource {

	/**
	 * @var array<int, OrderSnapshot>
	 */
	private array $orders = array();

	/**
	 * Seeds an order this source will {@see find()}.
	 *
	 * @param OrderSnapshot $order Order to seed.
	 */
	public function seed( OrderSnapshot $order ): void {
		$this->orders[ $order->order_id() ] = $order;
	}

	public function find( int $order_id ): ?OrderSnapshot {
		return $this->orders[ $order_id ] ?? null;
	}

	public function find_ids_by_status( string $status ): array {
		return array_values(
			array_map(
				static fn( OrderSnapshot $order ): int => $order->order_id(),
				array_filter(
					$this->orders,
					static fn( OrderSnapshot $order ): bool => $order->status() === $status
				)
			)
		);
	}

	public function list_summaries( array $statuses, int $page, int $per_page, string $search = '' ): \MPCF\Domain\OperationalOrderListResult {
		$matches = array_values(
			array_filter(
				$this->orders,
				static function ( OrderSnapshot $order ) use ( $statuses, $search ): bool {
					if ( array() !== $statuses && ! in_array( $order->status(), $statuses, true ) ) {
						return false;
					}

					if ( '' === $search ) {
						return true;
					}

					return str_contains( $order->order_number(), $search )
						|| str_contains( strtolower( $order->customer_name() ), strtolower( $search ) );
				}
			)
		);

		$total = count( $matches );
		$slice = array_slice( $matches, ( $page - 1 ) * $per_page, $per_page );
		$items = array();

		foreach ( $slice as $order ) {
			$items[] = \MPCF\Domain\OperationalOrderSummary::create(
				$order->order_id(),
				$order->order_number(),
				$order->customer_name(),
				$order->status(),
				new \DateTimeImmutable( '2026-08-01 10:00:00' )
			);
		}

		return new \MPCF\Domain\OperationalOrderListResult( $items, $total, $page, $per_page );
	}

	public function summaries_by_ids( array $order_ids ): array {
		$items = array();

		foreach ( $order_ids as $order_id ) {
			$order = $this->find( (int) $order_id );

			if ( null === $order ) {
				continue;
			}

			$items[] = \MPCF\Domain\OperationalOrderSummary::create(
				$order->order_id(),
				$order->order_number(),
				$order->customer_name(),
				$order->status(),
				new \DateTimeImmutable( '2026-08-01 10:00:00' )
			);
		}

		return $items;
	}
}
