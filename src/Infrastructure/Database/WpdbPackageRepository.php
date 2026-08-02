<?php
/**
 * Database-backed package repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

use DateTimeImmutable;
use MPCF\Domain\Repository\PackageRepository;
use MPCF\Domain\Shipping\Package;

/**
 * The only class that reads or writes `mpcf_packages`.
 */
final class WpdbPackageRepository implements PackageRepository {

	/**
	 * Finds a package by its own id.
	 *
	 * @param int $id Package id.
	 */
	public function find( int $id ): ?Package {
		global $wpdb;

		$table = Schema::table( Schema::PACKAGES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Every package for a shipment, in `seq` order.
	 *
	 * @param int $shipment_id Shipment id.
	 */
	public function find_for_shipment( int $shipment_id ): array {
		global $wpdb;

		$table = Schema::table( Schema::PACKAGES );
		$rows  = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE shipment_id = %d ORDER BY seq ASC", $shipment_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows ?? array() );
	}

	/**
	 * The next 1-indexed `seq` value for a new package on a shipment.
	 *
	 * @param int $shipment_id Shipment id.
	 */
	public function next_seq_for_shipment( int $shipment_id ): int {
		global $wpdb;

		$table = Schema::table( Schema::PACKAGES );
		$max   = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
			$wpdb->prepare( "SELECT MAX(seq) FROM {$table} WHERE shipment_id = %d", $shipment_id )
		);

		return null === $max ? 1 : ( (int) $max + 1 );
	}

	/**
	 * Inserts a brand-new package and returns its assigned id.
	 *
	 * @param Package $package A package built by {@see Package::create()}.
	 */
	public function insert( Package $package ): int {
		global $wpdb;

		$table = Schema::table( Schema::PACKAGES );
		$spec  = $package->spec();

		$wpdb->insert(
			$table,
			array(
				'shipment_id'     => $package->shipment_id(),
				'seq'             => $package->seq(),
				'weight_grams'    => $spec->weight_grams(),
				'length_mm'       => $spec->length_mm(),
				'width_mm'        => $spec->width_mm(),
				'height_mm'       => $spec->height_mm(),
				'tracking_number' => $package->tracking_number(),
				'label_path'      => $package->label_path(),
				'created_at'      => $package->created_at()->format( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Persists a mutation to an existing package.
	 *
	 * @param Package $package Package to persist.
	 */
	public function save( Package $package ): void {
		global $wpdb;

		$table = Schema::table( Schema::PACKAGES );
		$spec  = $package->spec();

		$wpdb->update(
			$table,
			array(
				'weight_grams'    => $spec->weight_grams(),
				'length_mm'       => $spec->length_mm(),
				'width_mm'        => $spec->width_mm(),
				'height_mm'       => $spec->height_mm(),
				'tracking_number' => $package->tracking_number(),
				'label_path'      => $package->label_path(),
			),
			array( 'id' => $package->id() ),
			array( '%d', '%d', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Deletes a package outright.
	 *
	 * @param int $id Package id.
	 */
	public function delete( int $id ): void {
		global $wpdb;

		$table = Schema::table( Schema::PACKAGES );
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Assembles a package from one `ARRAY_A` row.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 */
	private function hydrate( array $row ): Package {
		return Package::from_array(
			array(
				'id'              => (int) $row['id'],
				'shipment_id'     => (int) $row['shipment_id'],
				'seq'             => (int) $row['seq'],
				'weight_grams'    => null === $row['weight_grams'] ? null : (int) $row['weight_grams'],
				'length_mm'       => null === $row['length_mm'] ? null : (int) $row['length_mm'],
				'width_mm'        => null === $row['width_mm'] ? null : (int) $row['width_mm'],
				'height_mm'       => null === $row['height_mm'] ? null : (int) $row['height_mm'],
				'tracking_number' => $row['tracking_number'],
				'label_path'      => $row['label_path'],
				'created_at'      => new DateTimeImmutable( (string) $row['created_at'] ),
			)
		);
	}
}
