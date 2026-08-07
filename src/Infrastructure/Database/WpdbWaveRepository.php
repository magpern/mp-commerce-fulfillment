<?php
/**
 * Database-backed wave repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

use DateTimeImmutable;
use MPCF\Domain\Repository\WaveRepository;
use MPCF\Domain\Wave\Wave;
use MPCF\Domain\Wave\WaveMember;
use MPCF\Domain\Wave\WaveState;

/**
 * The only class that reads/writes `mpcf_waves` and `mpcf_wave_members`.
 * `save()` implements optimistic locking on `version`.
 */
final class WpdbWaveRepository implements WaveRepository {

	/**
	 * Finds a wave by id (with members).
	 *
	 * @param int $id Wave id.
	 */
	public function find( int $id ): ?Wave {
		global $wpdb;

		$table = Schema::table( Schema::WAVES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		if ( null === $row ) {
			return null;
		}

		$wave = $this->hydrate( $row );
		$wave->replace_members( $this->load_members( $id ) );

		return $wave;
	}

	/**
	 * Inserts a new wave.
	 *
	 * @param Wave $wave Wave to insert.
	 */
	public function insert( Wave $wave ): ?int {
		global $wpdb;

		$table  = Schema::table( Schema::WAVES );
		$result = $wpdb->insert(
			$table,
			array(
				'warehouse_id'      => $wave->warehouse_id(),
				'owner_user_id'     => $wave->owner_user_id(),
				'state'             => $wave->state(),
				'version'           => $wave->version(),
				'title'             => $wave->title(),
				'settings_snapshot' => wp_json_encode( $wave->settings_snapshot() ),
				'created_at'        => $wave->created_at()->format( 'Y-m-d H:i:s' ),
				'updated_at'        => $wave->updated_at()->format( 'Y-m-d H:i:s' ),
				'activated_at'      => null === $wave->activated_at() ? null : $wave->activated_at()->format( 'Y-m-d H:i:s' ),
				'completed_at'      => null === $wave->completed_at() ? null : $wave->completed_at()->format( 'Y-m-d H:i:s' ),
				'abandoned_at'      => null === $wave->abandoned_at() ? null : $wave->abandoned_at()->format( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return null;
		}

		$id = (int) $wpdb->insert_id;
		$wave->assign_id( $id );
		$this->sync_members( $wave );

		return $id;
	}

	/**
	 * Persists mutations with optimistic lock.
	 *
	 * @param Wave $wave Wave to save.
	 */
	public function save( Wave $wave ): bool {
		global $wpdb;

		$table   = Schema::table( Schema::WAVES );
		$updated = $wpdb->update(
			$table,
			array(
				'warehouse_id'      => $wave->warehouse_id(),
				'owner_user_id'     => $wave->owner_user_id(),
				'state'             => $wave->state(),
				'title'             => $wave->title(),
				'settings_snapshot' => wp_json_encode( $wave->settings_snapshot() ),
				'updated_at'        => $wave->updated_at()->format( 'Y-m-d H:i:s' ),
				'activated_at'      => null === $wave->activated_at() ? null : $wave->activated_at()->format( 'Y-m-d H:i:s' ),
				'completed_at'      => null === $wave->completed_at() ? null : $wave->completed_at()->format( 'Y-m-d H:i:s' ),
				'abandoned_at'      => null === $wave->abandoned_at() ? null : $wave->abandoned_at()->format( 'Y-m-d H:i:s' ),
				'version'           => $wave->version() + 1,
			),
			array(
				'id'      => $wave->id(),
				'version' => $wave->version(),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ),
			array( '%d', '%d' )
		);

		if ( $updated ) {
			$wave->increment_version();

			return true;
		}

		return false;
	}

	/**
	 * Lists open waves for an owner.
	 *
	 * @param int      $owner_user_id Owner user id.
	 * @param int|null $warehouse_id  Optional warehouse.
	 * @param int      $limit         Limit.
	 * @param int      $offset        Offset.
	 * @return list<Wave>
	 */
	public function list_open_for_owner( int $owner_user_id, ?int $warehouse_id = null, int $limit = 50, int $offset = 0 ): array {
		return $this->list_open_internal( $owner_user_id, $warehouse_id, $limit, $offset );
	}

	/**
	 * Lists open waves.
	 *
	 * @param int|null $warehouse_id Optional warehouse.
	 * @param int      $limit        Limit.
	 * @param int      $offset       Offset.
	 * @return list<Wave>
	 */
	public function list_open( ?int $warehouse_id = null, int $limit = 50, int $offset = 0 ): array {
		return $this->list_open_internal( null, $warehouse_id, $limit, $offset );
	}

	/**
	 * Finds the open wave holding a fulfillment.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function find_open_for_fulfillment( int $fulfillment_id ): ?Wave {
		global $wpdb;

		$members = Schema::table( Schema::WAVE_MEMBERS );
		$waves   = Schema::table( Schema::WAVES );
		$open    = array( WaveState::DRAFT, WaveState::ACTIVE, WaveState::PAUSED );
		$in      = "'" . implode( "','", array_map( 'esc_sql', $open ) ) . "'";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Schema-built table names; state IN-list from constants.
		$sql = $wpdb->prepare(
			"SELECT w.* FROM {$waves} w
			INNER JOIN {$members} m ON m.wave_id = w.id
			WHERE m.fulfillment_id = %d AND w.state IN ({$in})
			LIMIT 1",
			$fulfillment_id
		);
		$row = $wpdb->get_row( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( null === $row ) {
			return null;
		}

		$wave = $this->hydrate( $row );
		$wave->replace_members( $this->load_members( (int) $wave->id() ) );

		return $wave;
	}

	/**
	 * Replaces membership rows for a wave.
	 *
	 * @param Wave $wave Wave.
	 */
	public function sync_members( Wave $wave ): void {
		global $wpdb;

		$wave_id = (int) $wave->id();

		if ( $wave_id <= 0 ) {
			return;
		}

		$table = Schema::table( Schema::WAVE_MEMBERS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE wave_id = %d", $wave_id ) );

		foreach ( $wave->members() as $member ) {
			$wpdb->insert(
				$table,
				array(
					'wave_id'        => $wave_id,
					'fulfillment_id' => $member->fulfillment_id(),
					'position'       => $member->position(),
					'joined_at'      => $member->joined_at()->format( 'Y-m-d H:i:s' ),
					'picked_at'      => null === $member->picked_at() ? null : $member->picked_at()->format( 'Y-m-d H:i:s' ),
				),
				array( '%d', '%d', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Deletes all membership rows for a wave.
	 *
	 * @param int $wave_id Wave id.
	 */
	public function delete_members( int $wave_id ): void {
		global $wpdb;

		$table = Schema::table( Schema::WAVE_MEMBERS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE wave_id = %d", $wave_id ) );
	}

	/**
	 * Shared open-list query.
	 *
	 * @param int|null $owner_user_id Owner filter.
	 * @param int|null $warehouse_id  Warehouse filter.
	 * @param int      $limit         Limit.
	 * @param int      $offset        Offset.
	 * @return list<Wave>
	 */
	private function list_open_internal( ?int $owner_user_id, ?int $warehouse_id, int $limit, int $offset ): array {
		global $wpdb;

		$table  = Schema::table( Schema::WAVES );
		$open   = array( WaveState::DRAFT, WaveState::ACTIVE, WaveState::PAUSED );
		$in     = "'" . implode( "','", array_map( 'esc_sql', $open ) ) . "'";
		$where  = array( "state IN ({$in})" );
		$params = array();

		if ( null !== $owner_user_id ) {
			$where[]  = 'owner_user_id = %d';
			$params[] = $owner_user_id;
		}

		if ( null !== $warehouse_id ) {
			$where[]  = 'warehouse_id = %d';
			$params[] = $warehouse_id;
		}

		$params[] = max( 1, $limit );
		$params[] = max( 0, $offset );

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC LIMIT %d OFFSET %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic WHERE from trusted fragments; values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$out  = array();

		foreach ( $rows as $row ) {
			$wave = $this->hydrate( $row );
			$wave->replace_members( $this->load_members( (int) $wave->id() ) );
			$out[] = $wave;
		}

		return $out;
	}

	/**
	 * Loads members for a wave.
	 *
	 * @param int $wave_id Wave id.
	 * @return list<WaveMember>
	 */
	private function load_members( int $wave_id ): array {
		global $wpdb;

		$table = Schema::table( Schema::WAVE_MEMBERS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE wave_id = %d ORDER BY position ASC, fulfillment_id ASC", $wave_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			static function ( array $row ): WaveMember {
				return WaveMember::from_array(
					array(
						'wave_id'        => (int) $row['wave_id'],
						'fulfillment_id' => (int) $row['fulfillment_id'],
						'position'       => (int) $row['position'],
						'joined_at'      => new DateTimeImmutable( (string) $row['joined_at'] ),
						'picked_at'      => null === $row['picked_at'] || '' === $row['picked_at']
							? null
							: new DateTimeImmutable( (string) $row['picked_at'] ),
					)
				);
			},
			$rows
		);
	}

	/**
	 * Hydrates a wave row (without members).
	 *
	 * @param array<string, mixed> $row DB row.
	 */
	private function hydrate( array $row ): Wave {
		$snapshot = array();
		if ( isset( $row['settings_snapshot'] ) && is_string( $row['settings_snapshot'] ) && '' !== $row['settings_snapshot'] ) {
			$decoded  = json_decode( $row['settings_snapshot'], true );
			$snapshot = is_array( $decoded ) ? $decoded : array();
		}

		return Wave::from_array(
			array(
				'id'                => (int) $row['id'],
				'warehouse_id'      => (int) $row['warehouse_id'],
				'owner_user_id'     => null === $row['owner_user_id'] || '' === $row['owner_user_id'] ? null : (int) $row['owner_user_id'],
				'state'             => (string) $row['state'],
				'version'           => (int) $row['version'],
				'title'             => (string) ( $row['title'] ?? '' ),
				'settings_snapshot' => $snapshot,
				'created_at'        => new DateTimeImmutable( (string) $row['created_at'] ),
				'updated_at'        => new DateTimeImmutable( (string) $row['updated_at'] ),
				'activated_at'      => null === $row['activated_at'] || '' === $row['activated_at']
					? null
					: new DateTimeImmutable( (string) $row['activated_at'] ),
				'completed_at'      => null === $row['completed_at'] || '' === $row['completed_at']
					? null
					: new DateTimeImmutable( (string) $row['completed_at'] ),
				'abandoned_at'      => null === $row['abandoned_at'] || '' === $row['abandoned_at']
					? null
					: new DateTimeImmutable( (string) $row['abandoned_at'] ),
				'members'           => array(),
			)
		);
	}
}
