<?php
/**
 * In-memory test double for the fulfillment repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Doubles;

use MPCF\Domain\Fulfillment;
use MPCF\Domain\Repository\FulfillmentRepository;

/**
 * In-memory fulfillment store; no real database involved.
 */
final class InMemoryFulfillmentRepository implements FulfillmentRepository {

	/**
	 * @var array<int, Fulfillment>
	 */
	private array $rows = array();

	/**
	 * @var int
	 */
	private int $next_id = 1;

	public function find( int $id ): ?Fulfillment {
		$stored = $this->rows[ $id ] ?? null;

		// A fresh hydration per call, exactly like the real Wpdb-backed
		// repository — never the same object instance twice, so two
		// "concurrent requests" each loading the same row get independent
		// copies, the way the optimistic lock actually needs to be tested.
		return null === $stored ? null : Fulfillment::from_array( $stored->to_array() );
	}

	public function find_by_order_id( int $order_id ): ?Fulfillment {
		foreach ( $this->rows as $fulfillment ) {
			if ( $fulfillment->order_id() === $order_id ) {
				return Fulfillment::from_array( $fulfillment->to_array() );
			}
		}

		return null;
	}

	public function insert( Fulfillment $fulfillment ): int {
		$id = $this->next_id++;

		$this->rows[ $id ] = Fulfillment::from_array( array( 'id' => $id ) + $fulfillment->to_array() );

		return $id;
	}

	public function save( Fulfillment $fulfillment ): bool {
		$current = $this->rows[ $fulfillment->id() ] ?? null;

		if ( null === $current || $current->version() !== $fulfillment->version() ) {
			return false;
		}

		$this->rows[ $fulfillment->id() ] = Fulfillment::from_array(
			array_merge( $fulfillment->to_array(), array( 'version' => $fulfillment->version() + 1 ) )
		);

		$fulfillment->increment_version();

		return true;
	}

	/**
	 * Test helper: the row actually stored for an id, bypassing the
	 * optimistic-lock semantics {@see find()} would apply.
	 */
	public function stored( int $id ): ?Fulfillment {
		return $this->rows[ $id ] ?? null;
	}
}
