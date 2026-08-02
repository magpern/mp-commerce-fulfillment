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
}
