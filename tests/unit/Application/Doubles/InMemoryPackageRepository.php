<?php
/**
 * In-memory test double for the package repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Doubles;

use MPCF\Domain\Repository\PackageRepository;
use MPCF\Domain\Shipping\Package;

/**
 * In-memory package store; no real database involved.
 */
final class InMemoryPackageRepository implements PackageRepository {

	/**
	 * @var array<int, Package>
	 */
	private array $rows = array();

	/**
	 * @var int
	 */
	private int $next_id = 1;

	public function find( int $id ): ?Package {
		$stored = $this->rows[ $id ] ?? null;

		return null === $stored ? null : Package::from_array( $stored->to_array() );
	}

	public function find_for_shipment( int $shipment_id ): array {
		$matches = array_values( array_filter( $this->rows, static fn( Package $p ): bool => $p->shipment_id() === $shipment_id ) );

		usort( $matches, static fn( Package $a, Package $b ): int => $a->seq() <=> $b->seq() );

		return array_map( static fn( Package $p ): Package => Package::from_array( $p->to_array() ), $matches );
	}

	public function next_seq_for_shipment( int $shipment_id ): int {
		$max = 0;

		foreach ( $this->rows as $package ) {
			if ( $package->shipment_id() === $shipment_id ) {
				$max = max( $max, $package->seq() );
			}
		}

		return $max + 1;
	}

	public function insert( Package $package ): int {
		$id = $this->next_id++;

		$this->rows[ $id ] = Package::from_array( array( 'id' => $id ) + $package->to_array() );

		return $id;
	}

	public function save( Package $package ): void {
		$this->rows[ $package->id() ] = Package::from_array( $package->to_array() );
	}

	public function delete( int $id ): void {
		unset( $this->rows[ $id ] );
	}
}
