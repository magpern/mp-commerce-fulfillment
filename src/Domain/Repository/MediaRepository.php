<?php
/**
 * Persistence contract for package photography evidence.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Repository;

use DateTimeImmutable;
use MPCF\Domain\Media\PhotoRecord;

/**
 * Implemented in Infrastructure ({@see \MPCF\Infrastructure\Database\WpdbMediaRepository}).
 * Create / get / list / soft_delete / counts / next_sequence only — no hard
 * delete and no arbitrary update (Part VIII).
 */
interface MediaRepository {

	/**
	 * Inserts a brand-new photo and returns its assigned id.
	 *
	 * @param PhotoRecord $record A record built by {@see PhotoRecord::create()}.
	 */
	public function insert( PhotoRecord $record ): int;

	/**
	 * Loads one photo by id, or null when missing.
	 *
	 * @param int $photo_id Photo id.
	 */
	public function get( int $photo_id ): ?PhotoRecord;

	/**
	 * Lists photos for a fulfillment, oldest sequence first.
	 *
	 * @param int  $fulfillment_id  Fulfillment id.
	 * @param bool $include_deleted Whether to include soft-deleted rows.
	 * @return list<PhotoRecord>
	 */
	public function list_for_fulfillment( int $fulfillment_id, bool $include_deleted = false ): array;

	/**
	 * Lists photos for a package, oldest sequence first.
	 *
	 * @param int  $package_id      Package id.
	 * @param bool $include_deleted Whether to include soft-deleted rows.
	 * @return list<PhotoRecord>
	 */
	public function list_for_package( int $package_id, bool $include_deleted = false ): array;

	/**
	 * Count of active (non-deleted) photos for a fulfillment.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function count_active_for_fulfillment( int $fulfillment_id ): int;

	/**
	 * Count of active package-kind photos for a fulfillment
	 * (`kind = package` AND `deleted_at IS NULL`).
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function count_active_package_photos( int $fulfillment_id ): int;

	/**
	 * Next 1-indexed sequence value for a fulfillment.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function next_sequence( int $fulfillment_id ): int;

	/**
	 * Soft-deletes a photo by setting `deleted_at`. Idempotent: returns
	 * true when newly deleted or already deleted; false when missing.
	 *
	 * @param int               $photo_id Photo id.
	 * @param DateTimeImmutable $now      Soft-delete timestamp.
	 */
	public function soft_delete( int $photo_id, DateTimeImmutable $now ): bool;
}
