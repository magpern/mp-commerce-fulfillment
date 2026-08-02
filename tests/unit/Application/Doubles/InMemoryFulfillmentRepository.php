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

	public function find_all_by_order_id( int $order_id ): array {
		return array_values(
			array_map(
				static fn( Fulfillment $fulfillment ): Fulfillment => Fulfillment::from_array( $fulfillment->to_array() ),
				array_filter(
					$this->rows,
					static fn( Fulfillment $fulfillment ): bool => $fulfillment->order_id() === $order_id
				)
			)
		);
	}

	public function insert( Fulfillment $fulfillment ): ?int {
		foreach ( $this->rows as $row ) {
			// Mirrors the real repository's (order_id, order_source) unique
			// index (Schema::fulfillments_order_unique_index_ddl()) so
			// IntakeService's race-fallback path is unit-testable without a
			// database.
			if ( $row->order_id() === $fulfillment->order_id() && $row->order_source() === $fulfillment->order_source() ) {
				return null;
			}
		}

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
