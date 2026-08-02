<?php
/**
 * In-memory test double for the fulfillment repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Doubles;

use DateTimeImmutable;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentQuery;
use MPCF\Domain\FulfillmentQueryResult;
use MPCF\Domain\Repository\FulfillmentRepository;

/**
 * In-memory fulfillment store; no real database involved.
 */
final class InMemoryFulfillmentRepository implements FulfillmentRepository {

	/**
	 * @var array<int, Fulfillment>
	 */
	private array $rows = array();

	/**
	 * @var int
	 */
	private int $next_id = 1;

	public function find( int $id ): ?Fulfillment {
		$stored = $this->rows[ $id ] ?? null;

		// A fresh hydration per call, exactly like the real Wpdb-backed
		// repository — never the same object instance twice, so two
		// "concurrent requests" each loading the same row get independent
		// copies, the way the optimistic lock actually needs to be tested.
		return null === $stored ? null : Fulfillment::from_array( $stored->to_array() );
	}

	public function find_by_order_id( int $order_id ): ?Fulfillment {
		foreach ( $this->rows as $fulfillment ) {
			if ( $fulfillment->order_id() === $order_id ) {
				return Fulfillment::from_array( $fulfillment->to_array() );
			}
		}

		return null;
	}

	public function find_all_by_order_id( int $order_id ): array {
		return array_values(
			array_map(
				static fn( Fulfillment $fulfillment ): Fulfillment => Fulfillment::from_array( $fulfillment->to_array() ),
				array_filter(
					$this->rows,
					static fn( Fulfillment $fulfillment ): bool => $fulfillment->order_id() === $order_id
				)
			)
		);
	}

	public function insert( Fulfillment $fulfillment ): ?int {
		foreach ( $this->rows as $row ) {
			// Mirrors the real repository's (order_id, order_source) unique
			// index (Schema::fulfillments_order_unique_index_ddl()) so
			// IntakeService's race-fallback path is unit-testable without a
			// database.
			if ( $row->order_id() === $fulfillment->order_id() && $row->order_source() === $fulfillment->order_source() ) {
				return null;
			}
		}

		$id = $this->next_id++;

		$this->rows[ $id ] = Fulfillment::from_array( array( 'id' => $id ) + $fulfillment->to_array() );

		return $id;
	}

	public function save( Fulfillment $fulfillment ): bool {
		$current = $this->rows[ $fulfillment->id() ] ?? null;

		if ( null === $current || $current->version() !== $fulfillment->version() ) {
			return false;
		}

		$this->rows[ $fulfillment->id() ] = Fulfillment::from_array(
			array_merge( $fulfillment->to_array(), array( 'version' => $fulfillment->version() + 1 ) )
		);

		$fulfillment->increment_version();

		return true;
	}

	public function query( FulfillmentQuery $query ): FulfillmentQueryResult {
		$matches = array_values( array_filter( $this->rows, fn( Fulfillment $f ): bool => $this->matches( $f, $query ) ) );

		usort(
			$matches,
			static function ( Fulfillment $a, Fulfillment $b ) use ( $query ): int {
				$column = $query->order_by();
				$va     = 'priority' === $column ? $a->priority() : ( 'state_entered_at' === $column ? $a->state_entered_at()->getTimestamp() : $a->created_at()->getTimestamp() );
				$vb     = 'priority' === $column ? $b->priority() : ( 'state_entered_at' === $column ? $b->state_entered_at()->getTimestamp() : $b->created_at()->getTimestamp() );
				$cmp    = $va <=> $vb;

				return 'ASC' === strtoupper( $query->order() ) ? $cmp : -$cmp;
			}
		);

		$total = count( $matches );
		$page  = array_slice( $matches, $query->offset(), $query->per_page() );

		return new FulfillmentQueryResult(
			array_map( static fn( Fulfillment $f ): Fulfillment => Fulfillment::from_array( $f->to_array() ), $page ),
			$total,
			$query->page(),
			$query->per_page()
		);
	}

	public function touch( int $id, int $expected_version ): bool {
		$current = $this->rows[ $id ] ?? null;

		if ( null === $current || $current->version() !== $expected_version ) {
			return false;
		}

		$this->rows[ $id ] = Fulfillment::from_array(
			array_merge( $current->to_array(), array( 'version' => $expected_version + 1 ) )
		);

		return true;
	}

	public function count_in_states( array $states ): int {
		if ( array() === $states ) {
			return 0;
		}

		return count( array_filter( $this->rows, static fn( Fulfillment $f ): bool => in_array( $f->state(), $states, true ) ) );
	}

	private function matches( Fulfillment $fulfillment, FulfillmentQuery $query ): bool {
		if ( array() !== $query->states() && ! in_array( $fulfillment->state(), $query->states(), true ) ) {
			return false;
		}

		$assignee = $query->assignee();

		if ( FulfillmentQuery::SENTINEL_UNASSIGNED === $assignee && null !== $fulfillment->assignee_id() ) {
			return false;
		}

		if ( is_int( $assignee ) && $fulfillment->assignee_id() !== $assignee ) {
			return false;
		}

		if ( null !== $query->fulfillment_ids() && ! in_array( $fulfillment->id(), $query->fulfillment_ids(), true ) ) {
			return false;
		}

		if ( null !== $query->min_age_seconds() ) {
			$threshold = ( new DateTimeImmutable() )->getTimestamp() - $query->min_age_seconds();

			if ( $fulfillment->state_entered_at()->getTimestamp() > $threshold ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Test helper: the row actually stored for an id, bypassing the
	 * optimistic-lock semantics {@see find()} would apply.
	 */
	public function stored( int $id ): ?Fulfillment {
		return $this->rows[ $id ] ?? null;
	}
}
