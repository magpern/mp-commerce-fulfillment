<?php
/**
 * Persistence contract for waves and memberships.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Repository;

use MPCF\Domain\Wave\Wave;

/**
 * Implemented in Infrastructure ({@see \MPCF\Infrastructure\Database\WpdbWaveRepository}).
 */
interface WaveRepository {

	/**
	 * Finds a wave by id (with members).
	 *
	 * @param int $id Wave id.
	 */
	public function find( int $id ): ?Wave;

	/**
	 * Inserts a new draft wave. Returns the assigned id, or null on failure.
	 *
	 * @param Wave $wave Wave to insert.
	 */
	public function insert( Wave $wave ): ?int;

	/**
	 * Persists mutations with an optimistic lock on `version`.
	 *
	 * @param Wave $wave Wave to save.
	 */
	public function save( Wave $wave ): bool;

	/**
	 * Lists open waves for an owner (and optionally warehouse), newest first.
	 *
	 * @param int      $owner_user_id Owner user id.
	 * @param int|null $warehouse_id  Optional warehouse filter.
	 * @param int      $limit         Max rows.
	 * @param int      $offset        Offset.
	 * @return list<Wave>
	 */
	public function list_open_for_owner( int $owner_user_id, ?int $warehouse_id = null, int $limit = 50, int $offset = 0 ): array;

	/**
	 * Lists open waves (any owner), newest first.
	 *
	 * @param int|null $warehouse_id Optional warehouse filter.
	 * @param int      $limit        Max rows.
	 * @param int      $offset       Offset.
	 * @return list<Wave>
	 */
	public function list_open( ?int $warehouse_id = null, int $limit = 50, int $offset = 0 ): array;

	/**
	 * Finds the open wave that currently holds `$fulfillment_id`, if any.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function find_open_for_fulfillment( int $fulfillment_id ): ?Wave;

	/**
	 * Persists membership rows for a wave (replace-set for the wave's members).
	 *
	 * @param Wave $wave Wave whose members should be written.
	 */
	public function sync_members( Wave $wave ): void;

	/**
	 * Deletes all membership rows for a wave (abandon / cleanup).
	 *
	 * @param int $wave_id Wave id.
	 */
	public function delete_members( int $wave_id ): void;
}
