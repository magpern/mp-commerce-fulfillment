<?php
/**
 * Database-backed shipment repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

use DateTimeImmutable;
use MPCF\Domain\Repository\ShipmentRepository;
use MPCF\Domain\Shipping\Shipment;

/**
 * The only class that reads or writes `mpcf_shipments`.
 */
final class WpdbShipmentRepository implements ShipmentRepository {

	/**
	 * Finds a shipment by its own id.
	 *
	 * @param int $id Shipment id.
	 */
	public function find( int $id ): ?Shipment {
		global $wpdb;

		$table = Schema::table( Schema::SHIPMENTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Every shipment for a fulfillment, oldest first.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function find_for_fulfillment( int $fulfillment_id ): array {
		global $wpdb;

		$table = Schema::table( Schema::SHIPMENTS );
		$rows  = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE fulfillment_id = %d ORDER BY id ASC", $fulfillment_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows ?? array() );
	}

	/**
	 * Inserts a brand-new shipment and returns its assigned id.
	 *
	 * @param Shipment $shipment A shipment built by {@see Shipment::create()}.
	 */
	public function insert( Shipment $shipment ): int {
		global $wpdb;

		$table        = Schema::table( Schema::SHIPMENTS );
		$shipped_at   = $shipment->shipped_at();
		$delivered_at = $shipment->delivered_at();

		$wpdb->insert(
			$table,
			array(
				'fulfillment_id'  => $shipment->fulfillment_id(),
				'carrier_id'      => $shipment->carrier_id(),
				'service'         => $shipment->service(),
				'tracking_number' => $shipment->tracking()->number(),
				'tracking_url'    => $shipment->tracking()->url(),
				'status'          => $shipment->status(),
				'shipped_at'      => null === $shipped_at ? null : $shipped_at->format( 'Y-m-d H:i:s' ),
				'delivered_at'    => null === $delivered_at ? null : $delivered_at->format( 'Y-m-d H:i:s' ),
				'created_at'      => $shipment->created_at()->format( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Persists a mutation to an existing shipment.
	 *
	 * @param Shipment $shipment Shipment to persist.
	 */
	public function save( Shipment $shipment ): void {
		global $wpdb;

		$table        = Schema::table( Schema::SHIPMENTS );
		$shipped_at   = $shipment->shipped_at();
		$delivered_at = $shipment->delivered_at();

		$wpdb->update(
			$table,
			array(
				'carrier_id'      => $shipment->carrier_id(),
				'service'         => $shipment->service(),
				'tracking_number' => $shipment->tracking()->number(),
				'tracking_url'    => $shipment->tracking()->url(),
				'status'          => $shipment->status(),
				'shipped_at'      => null === $shipped_at ? null : $shipped_at->format( 'Y-m-d H:i:s' ),
				'delivered_at'    => null === $delivered_at ? null : $delivered_at->format( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $shipment->id() ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Deletes a shipment outright.
	 *
	 * @param int $id Shipment id.
	 */
	public function delete( int $id ): void {
		global $wpdb;

		$table = Schema::table( Schema::SHIPMENTS );
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Assembles a shipment from one `ARRAY_A` row.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 */
	private function hydrate( array $row ): Shipment {
		return Shipment::from_array(
			array(
				'id'              => (int) $row['id'],
				'fulfillment_id'  => (int) $row['fulfillment_id'],
				'carrier_id'      => (string) $row['carrier_id'],
				'service'         => $row['service'],
				'tracking_number' => $row['tracking_number'],
				'tracking_url'    => $row['tracking_url'],
				'status'          => (string) $row['status'],
				'shipped_at'      => null === $row['shipped_at'] ? null : new DateTimeImmutable( (string) $row['shipped_at'] ),
				'delivered_at'    => null === $row['delivered_at'] ? null : new DateTimeImmutable( (string) $row['delivered_at'] ),
				'created_at'      => new DateTimeImmutable( (string) $row['created_at'] ),
			)
		);
	}
}
