<?php
/**
 * Persistence contract for shipments.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Repository;

use MPCF\Domain\Shipping\Shipment;

/**
 * Implemented in Infrastructure ({@see \MPCF\Infrastructure\Database\WpdbShipmentRepository}),
 * confined there per invariant I7. Shipments carry no optimistic-lock
 * version of their own — concurrency on a shipment write is governed by
 * the owning fulfillment's version via {@see FulfillmentRepository::touch()}
 * (Architecture Plan §IV.5.7: "one token covers the whole aggregate").
 */
interface ShipmentRepository {

	/**
	 * Finds a shipment by its own id.
	 *
	 * @param int $id Shipment id.
	 */
	public function find( int $id ): ?Shipment;

	/**
	 * Every shipment for a fulfillment, oldest first.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @return list<Shipment>
	 */
	public function find_for_fulfillment( int $fulfillment_id ): array;

	/**
	 * Inserts a brand-new shipment and returns its assigned id.
	 *
	 * @param Shipment $shipment A shipment built by {@see Shipment::create()}.
	 */
	public function insert( Shipment $shipment ): int;

	/**
	 * Persists a mutation to an existing shipment.
	 *
	 * @param Shipment $shipment Shipment to persist.
	 */
	public function save( Shipment $shipment ): void;

	/**
	 * Deletes a shipment outright. Callers must check
	 * {@see Shipment::is_deletable()} first — this method does not
	 * enforce that itself (Application-layer responsibility, matching the
	 * house convention that Infrastructure stays a thin persistence
	 * adapter).
	 *
	 * @param int $id Shipment id.
	 */
	public function delete( int $id ): void;
}
