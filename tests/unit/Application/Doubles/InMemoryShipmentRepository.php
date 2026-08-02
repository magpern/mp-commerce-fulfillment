<?php
/**
 * In-memory test double for the shipment repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Doubles;

use MPCF\Domain\Repository\ShipmentRepository;
use MPCF\Domain\Shipping\Shipment;

/**
 * In-memory shipment store; no real database involved.
 */
final class InMemoryShipmentRepository implements ShipmentRepository {

	/**
	 * @var array<int, Shipment>
	 */
	private array $rows = array();

	/**
	 * @var int
	 */
	private int $next_id = 1;

	public function find( int $id ): ?Shipment {
		$stored = $this->rows[ $id ] ?? null;

		return null === $stored ? null : Shipment::from_array( $stored->to_array() );
	}

	public function find_for_fulfillment( int $fulfillment_id ): array {
		return array_values(
			array_map(
				static fn( Shipment $s ): Shipment => Shipment::from_array( $s->to_array() ),
				array_filter( $this->rows, static fn( Shipment $s ): bool => $s->fulfillment_id() === $fulfillment_id )
			)
		);
	}

	public function insert( Shipment $shipment ): int {
		$id = $this->next_id++;

		$this->rows[ $id ] = Shipment::from_array( array( 'id' => $id ) + $shipment->to_array() );

		return $id;
	}

	public function save( Shipment $shipment ): void {
		$this->rows[ $shipment->id() ] = Shipment::from_array( $shipment->to_array() );
	}

	public function delete( int $id ): void {
		unset( $this->rows[ $id ] );
	}
}
