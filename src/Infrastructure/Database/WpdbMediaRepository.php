<?php
/**
 * Database-backed package photography repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

use DateTimeImmutable;
use DateTimeZone;
use MPCF\Domain\Media\PhotoKind;
use MPCF\Domain\Media\PhotoRecord;
use MPCF\Domain\Repository\MediaRepository;

/**
 * The only class that reads or writes `mpcf_media`. Soft-delete only —
 * no hard delete and no arbitrary update.
 */
final class WpdbMediaRepository implements MediaRepository {

	/**
	 * Inserts a brand-new photo and returns its assigned id.
	 *
	 * @param PhotoRecord $record A record built by {@see PhotoRecord::create()}.
	 */
	public function insert( PhotoRecord $record ): int {
		global $wpdb;

		$table = Schema::table( Schema::MEDIA );

		$wpdb->insert(
			$table,
			array(
				'fulfillment_id'     => $record->fulfillment_id(),
				'package_id'         => $record->package_id(),
				'kind'               => $record->kind(),
				'file_path'          => $record->file_path(),
				'thumb_path'         => $record->thumb_path(),
				'mime'               => $record->mime(),
				'bytes'              => $record->bytes(),
				'sha256'             => $record->sha256(),
				'processing_version' => $record->processing_version(),
				'width'              => $record->width(),
				'height'             => $record->height(),
				'seq'                => $record->seq(),
				'captured_by'        => $record->captured_by(),
				'created_at'         => $record->created_at()->format( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Loads one photo by id, or null when missing.
	 *
	 * @param int $photo_id Photo id.
	 */
	public function get( int $photo_id ): ?PhotoRecord {
		global $wpdb;

		$table = Schema::table( Schema::MEDIA );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a fixed schema literal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $photo_id ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Lists photos for a fulfillment, oldest sequence first.
	 *
	 * @param int  $fulfillment_id  Fulfillment id.
	 * @param bool $include_deleted Whether to include soft-deleted rows.
	 * @return list<PhotoRecord>
	 */
	public function list_for_fulfillment( int $fulfillment_id, bool $include_deleted = false ): array {
		global $wpdb;

		$table = Schema::table( Schema::MEDIA );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a fixed schema literal.
		$sql = "SELECT * FROM {$table} WHERE fulfillment_id = %d";

		if ( ! $include_deleted ) {
			$sql .= ' AND deleted_at IS NULL';
		}

		$sql .= ' ORDER BY seq ASC, id ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal with placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $fulfillment_id ), ARRAY_A );

		return $this->hydrate_list( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Lists photos for a package, oldest sequence first.
	 *
	 * @param int  $package_id      Package id.
	 * @param bool $include_deleted Whether to include soft-deleted rows.
	 * @return list<PhotoRecord>
	 */
	public function list_for_package( int $package_id, bool $include_deleted = false ): array {
		global $wpdb;

		$table = Schema::table( Schema::MEDIA );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a fixed schema literal.
		$sql = "SELECT * FROM {$table} WHERE package_id = %d";

		if ( ! $include_deleted ) {
			$sql .= ' AND deleted_at IS NULL';
		}

		$sql .= ' ORDER BY seq ASC, id ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal with placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $package_id ), ARRAY_A );

		return $this->hydrate_list( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Count of active (non-deleted) photos for a fulfillment.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function count_active_for_fulfillment( int $fulfillment_id ): int {
		global $wpdb;

		$table = Schema::table( Schema::MEDIA );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a fixed schema literal.
		$sql = "SELECT COUNT(*) FROM {$table} WHERE fulfillment_id = %d AND deleted_at IS NULL";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal with placeholders only.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $fulfillment_id ) );
	}

	/**
	 * Count of active package-kind photos for a fulfillment.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function count_active_package_photos( int $fulfillment_id ): int {
		global $wpdb;

		$table = Schema::table( Schema::MEDIA );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a fixed schema literal.
		$sql = "SELECT COUNT(*) FROM {$table} WHERE fulfillment_id = %d AND kind = %s AND deleted_at IS NULL";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal with placeholders only.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $fulfillment_id, PhotoKind::PACKAGE ) );
	}

	/**
	 * Next 1-indexed sequence value for a fulfillment.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function next_sequence( int $fulfillment_id ): int {
		global $wpdb;

		$table = Schema::table( Schema::MEDIA );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a fixed schema literal.
		$sql = "SELECT MAX(seq) FROM {$table} WHERE fulfillment_id = %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal with placeholders only.
		$max = $wpdb->get_var( $wpdb->prepare( $sql, $fulfillment_id ) );

		return ( null === $max || '' === $max ) ? 1 : ( (int) $max + 1 );
	}

	/**
	 * Soft-deletes a photo by setting `deleted_at`. Idempotent.
	 *
	 * @param int               $photo_id Photo id.
	 * @param DateTimeImmutable $now      Soft-delete timestamp.
	 */
	public function soft_delete( int $photo_id, DateTimeImmutable $now ): bool {
		global $wpdb;

		$existing = $this->get( $photo_id );

		if ( null === $existing ) {
			return false;
		}

		if ( $existing->is_deleted() ) {
			return true;
		}

		$table = Schema::table( Schema::MEDIA );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a fixed schema literal.
		$sql = "UPDATE {$table} SET deleted_at = %s WHERE id = %d AND deleted_at IS NULL";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal with placeholders only.
		$wpdb->query( $wpdb->prepare( $sql, $now->format( 'Y-m-d H:i:s' ), $photo_id ) );

		return true;
	}

	/**
	 * Lists photos eligible for retention purge by age cutoff.
	 *
	 * @param DateTimeImmutable $cutoff Inclusive age cutoff (UTC).
	 * @param int               $limit  Max rows.
	 * @return list<PhotoRecord>
	 */
	public function list_purge_candidates( DateTimeImmutable $cutoff, int $limit ): array {
		global $wpdb;

		$limit = max( 1, min( 500, $limit ) );
		$table = Schema::table( Schema::MEDIA );
		$utc   = $cutoff->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a fixed schema literal.
		$sql = "SELECT * FROM {$table} WHERE purged_at IS NULL AND created_at <= %s ORDER BY created_at ASC, id ASC LIMIT %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sql placeholders prepared below; table name is a fixed schema literal.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $utc, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately; table name is Schema::table().

		return $this->hydrate_list( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Marks a photo purged and clears relative paths. Idempotent.
	 *
	 * @param int               $photo_id Photo id.
	 * @param DateTimeImmutable $now      Purge timestamp.
	 */
	public function mark_purged( int $photo_id, DateTimeImmutable $now ): bool {
		global $wpdb;

		$existing = $this->get( $photo_id );

		if ( null === $existing ) {
			return false;
		}

		if ( $existing->is_purged() ) {
			return true;
		}

		$table = Schema::table( Schema::MEDIA );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a fixed schema literal.
		$sql = "UPDATE {$table} SET purged_at = %s, file_path = '', thumb_path = '' WHERE id = %d AND purged_at IS NULL";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal with placeholders only.
		$wpdb->query( $wpdb->prepare( $sql, $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), $photo_id ) );

		return true;
	}

	/**
	 * Hydrates a list of database rows.
	 *
	 * @param list<array<string, mixed>> $rows Database rows.
	 * @return list<PhotoRecord>
	 */
	private function hydrate_list( array $rows ): array {
		$out = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $this->hydrate( $row );
			}
		}

		return $out;
	}

	/**
	 * Hydrates a database row into a PhotoRecord (UTC DATETIME).
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	private function hydrate( array $row ): PhotoRecord {
		$utc = new DateTimeZone( 'UTC' );

		return PhotoRecord::from_array(
			array(
				'id'                 => (int) $row['id'],
				'fulfillment_id'     => (int) $row['fulfillment_id'],
				'package_id'         => (int) $row['package_id'],
				'kind'               => (string) $row['kind'],
				'file_path'          => (string) $row['file_path'],
				'thumb_path'         => (string) $row['thumb_path'],
				'mime'               => (string) $row['mime'],
				'bytes'              => (int) $row['bytes'],
				'sha256'             => (string) $row['sha256'],
				'processing_version' => (int) $row['processing_version'],
				'width'              => (int) $row['width'],
				'height'             => (int) $row['height'],
				'seq'                => (int) $row['seq'],
				'captured_by'        => isset( $row['captured_by'] ) && null !== $row['captured_by'] && '' !== $row['captured_by']
					? (int) $row['captured_by']
					: null,
				'created_at'         => new DateTimeImmutable( (string) $row['created_at'], $utc ),
				'deleted_at'         => isset( $row['deleted_at'] ) && null !== $row['deleted_at'] && '' !== $row['deleted_at']
					? new DateTimeImmutable( (string) $row['deleted_at'], $utc )
					: null,
				'purged_at'          => isset( $row['purged_at'] ) && null !== $row['purged_at'] && '' !== $row['purged_at']
					? new DateTimeImmutable( (string) $row['purged_at'], $utc )
					: null,
			)
		);
	}
}
