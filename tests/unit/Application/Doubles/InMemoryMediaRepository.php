<?php
/**
 * In-memory MediaRepository for PhotoService tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Doubles;

use DateTimeImmutable;
use MPCF\Domain\Media\PhotoKind;
use MPCF\Domain\Media\PhotoRecord;
use MPCF\Domain\Repository\MediaRepository;

/**
 * In-memory photo store; no real database involved.
 */
final class InMemoryMediaRepository implements MediaRepository {

	/**
	 * @var array<int, PhotoRecord>
	 */
	private array $rows = array();

	/**
	 * @var int
	 */
	private int $next_id = 1;

	/**
	 * {@inheritDoc}
	 */
	public function insert( PhotoRecord $record ): int {
		$id = $this->next_id++;

		$this->rows[ $id ] = PhotoRecord::from_array( array( 'id' => $id ) + $record->to_array() );

		return $id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( int $photo_id ): ?PhotoRecord {
		$stored = $this->rows[ $photo_id ] ?? null;

		return null === $stored ? null : PhotoRecord::from_array( $stored->to_array() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_for_fulfillment( int $fulfillment_id, bool $include_deleted = false ): array {
		$out = array();

		foreach ( $this->rows as $row ) {
			if ( $row->fulfillment_id() !== $fulfillment_id ) {
				continue;
			}
			if ( ! $include_deleted && $row->is_deleted() ) {
				continue;
			}
			$out[] = PhotoRecord::from_array( $row->to_array() );
		}

		usort( $out, static fn( PhotoRecord $a, PhotoRecord $b ): int => $a->seq() <=> $b->seq() );

		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_for_package( int $package_id, bool $include_deleted = false ): array {
		$out = array();

		foreach ( $this->rows as $row ) {
			if ( $row->package_id() !== $package_id ) {
				continue;
			}
			if ( ! $include_deleted && $row->is_deleted() ) {
				continue;
			}
			$out[] = PhotoRecord::from_array( $row->to_array() );
		}

		usort( $out, static fn( PhotoRecord $a, PhotoRecord $b ): int => $a->seq() <=> $b->seq() );

		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function count_active_for_fulfillment( int $fulfillment_id ): int {
		$count = 0;

		foreach ( $this->rows as $row ) {
			if ( $row->fulfillment_id() === $fulfillment_id && $row->is_active() ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * {@inheritDoc}
	 */
	public function count_active_package_photos( int $fulfillment_id ): int {
		$count = 0;

		foreach ( $this->rows as $row ) {
			if ( $row->fulfillment_id() === $fulfillment_id && $row->is_active() && PhotoKind::PACKAGE === $row->kind() ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * {@inheritDoc}
	 */
	public function next_sequence( int $fulfillment_id ): int {
		$max = 0;

		foreach ( $this->rows as $row ) {
			if ( $row->fulfillment_id() === $fulfillment_id ) {
				$max = max( $max, $row->seq() );
			}
		}

		return $max + 1;
	}

	/**
	 * {@inheritDoc}
	 */
	public function soft_delete( int $photo_id, DateTimeImmutable $now ): bool {
		$existing = $this->get( $photo_id );

		if ( null === $existing ) {
			return false;
		}

		if ( $existing->is_deleted() ) {
			return true;
		}

		$data                    = $existing->to_array();
		$data['deleted_at']      = $now;
		$this->rows[ $photo_id ] = PhotoRecord::from_array( $data );

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_purge_candidates( DateTimeImmutable $cutoff, int $limit ): array {
		$limit = max( 1, min( 500, $limit ) );
		$out   = array();

		foreach ( $this->rows as $row ) {
			if ( $row->is_purged() ) {
				continue;
			}

			if ( $row->created_at() > $cutoff ) {
				continue;
			}

			$out[] = PhotoRecord::from_array( $row->to_array() );
		}

		usort(
			$out,
			static function ( PhotoRecord $a, PhotoRecord $b ): int {
				$cmp = $a->created_at() <=> $b->created_at();

				return 0 !== $cmp ? $cmp : ( (int) $a->id() <=> (int) $b->id() );
			}
		);

		return array_slice( $out, 0, $limit );
	}

	/**
	 * {@inheritDoc}
	 */
	public function mark_purged( int $photo_id, DateTimeImmutable $now ): bool {
		$existing = $this->get( $photo_id );

		if ( null === $existing ) {
			return false;
		}

		if ( $existing->is_purged() ) {
			return true;
		}

		$data                    = $existing->to_array();
		$data['purged_at']       = $now;
		$data['file_path']       = '';
		$data['thumb_path']      = '';
		$this->rows[ $photo_id ] = PhotoRecord::from_array( $data );

		return true;
	}
}
