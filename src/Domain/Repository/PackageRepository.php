<?php
/**
 * Persistence contract for packages.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Repository;

use MPCF\Domain\Shipping\Package;

/**
 * Implemented in Infrastructure ({@see \MPCF\Infrastructure\Database\WpdbPackageRepository}),
 * confined there per invariant I7.
 */
interface PackageRepository {

	/**
	 * Finds a package by its own id.
	 *
	 * @param int $id Package id.
	 */
	public function find( int $id ): ?Package;

	/**
	 * Every package for a shipment, in `seq` order.
	 *
	 * @param int $shipment_id Shipment id.
	 * @return list<Package>
	 */
	public function find_for_shipment( int $shipment_id ): array;

	/**
	 * The next 1-indexed `seq` value for a new package on a shipment — 1
	 * if the shipment has none yet.
	 *
	 * @param int $shipment_id Shipment id.
	 */
	public function next_seq_for_shipment( int $shipment_id ): int;

	/**
	 * Inserts a brand-new package and returns its assigned id.
	 *
	 * @param Package $package A package built by {@see Package::create()}.
	 */
	public function insert( Package $package ): int;

	/**
	 * Persists a mutation to an existing package.
	 *
	 * @param Package $package Package to persist.
	 */
	public function save( Package $package ): void;

	/**
	 * Deletes a package outright.
	 *
	 * @param int $id Package id.
	 */
	public function delete( int $id ): void;
}
