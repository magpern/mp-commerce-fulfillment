<?php
/**
 * In-memory wave repository for unit tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Doubles;

use MPCF\Domain\Repository\WaveRepository;
use MPCF\Domain\Wave\Wave;
use MPCF\Domain\Wave\WaveState;

/**
 * Array-backed wave store with optimistic locking.
 */
final class InMemoryWaveRepository implements WaveRepository {

	/**
	 * @var array<int, Wave>
	 */
	private array $rows = array();

	/**
	 * @var int
	 */
	private int $next_id = 1;

	public function find( int $id ): ?Wave {
		$stored = $this->rows[ $id ] ?? null;

		return null === $stored ? null : Wave::from_array( $stored->to_array() );
	}

	public function insert( Wave $wave ): ?int {
		$id = $this->next_id++;
		$wave->assign_id( $id );
		$this->rows[ $id ] = Wave::from_array( $wave->to_array() );

		return $id;
	}

	public function save( Wave $wave ): bool {
		$id      = (int) $wave->id();
		$current = $this->rows[ $id ] ?? null;

		if ( null === $current || $current->version() !== $wave->version() ) {
			return false;
		}

		$data              = $wave->to_array();
		$data['version']   = $wave->version() + 1;
		$this->rows[ $id ] = Wave::from_array( $data );
		$wave->increment_version();

		return true;
	}

	public function list_open_for_owner( int $owner_user_id, ?int $warehouse_id = null, int $limit = 50, int $offset = 0 ): array {
		return $this->filter_open( $owner_user_id, $warehouse_id, $limit, $offset );
	}

	public function list_open( ?int $warehouse_id = null, int $limit = 50, int $offset = 0 ): array {
		return $this->filter_open( null, $warehouse_id, $limit, $offset );
	}

	public function find_open_for_fulfillment( int $fulfillment_id ): ?Wave {
		foreach ( $this->rows as $wave ) {
			if ( ! $wave->is_open() ) {
				continue;
			}

			if ( null !== $wave->member( $fulfillment_id ) ) {
				return Wave::from_array( $wave->to_array() );
			}
		}

		return null;
	}

	public function sync_members( Wave $wave ): void {
		$id = (int) $wave->id();

		if ( isset( $this->rows[ $id ] ) ) {
			$stored = Wave::from_array( $this->rows[ $id ]->to_array() );
			$stored->replace_members( $wave->members() );
			$this->rows[ $id ] = $stored;
		}
	}

	public function delete_members( int $wave_id ): void {
		if ( ! isset( $this->rows[ $wave_id ] ) ) {
			return;
		}

		$wave = Wave::from_array( $this->rows[ $wave_id ]->to_array() );
		$wave->clear_members( $wave->updated_at() );
		$this->rows[ $wave_id ] = $wave;
	}

	/**
	 * @param int|null $owner_user_id Owner filter.
	 * @param int|null $warehouse_id  Warehouse filter.
	 * @param int      $limit         Limit.
	 * @param int      $offset        Offset.
	 * @return list<Wave>
	 */
	private function filter_open( ?int $owner_user_id, ?int $warehouse_id, int $limit, int $offset ): array {
		$matches = array();

		foreach ( $this->rows as $wave ) {
			if ( ! WaveState::is_open( $wave->state() ) ) {
				continue;
			}

			if ( null !== $owner_user_id && (int) $wave->owner_user_id() !== $owner_user_id ) {
				continue;
			}

			if ( null !== $warehouse_id && (int) $wave->warehouse_id() !== $warehouse_id ) {
				continue;
			}

			$matches[] = Wave::from_array( $wave->to_array() );
		}

		return array_slice( $matches, max( 0, $offset ), max( 1, $limit ) );
	}
}
