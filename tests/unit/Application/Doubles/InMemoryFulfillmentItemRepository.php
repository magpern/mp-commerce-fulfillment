<?php
/**
 * In-memory test double for the fulfillment item repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Doubles;

use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Repository\FulfillmentItemRepository;

/**
 * In-memory line item store; no real database involved.
 */
final class InMemoryFulfillmentItemRepository implements FulfillmentItemRepository {

	/**
	 * @var array<int, FulfillmentItem>
	 */
	private array $rows = array();

	/**
	 * @var int
	 */
	private int $next_id = 1;

	public function find_for_fulfillment( int $fulfillment_id ): array {
		// A fresh hydration per call, exactly like the real Wpdb-backed
		// repository (and matching InMemoryFulfillmentRepository::find()'s
		// own convention) — a caller that mutates a returned item and then
		// decides not to save() it must never see that mutation reflected
		// back in a later find_for_fulfillment() call.
		return array_values(
			array_map(
				static fn( FulfillmentItem $item ): FulfillmentItem => FulfillmentItem::from_array( $item->to_array() ),
				array_filter(
					$this->rows,
					static fn( FulfillmentItem $item ): bool => $item->fulfillment_id() === $fulfillment_id
				)
			)
		);
	}

	public function insert_all( array $items ): void {
		foreach ( $items as $item ) {
			$id                = $this->next_id++;
			$this->rows[ $id ] = FulfillmentItem::from_array( array( 'id' => $id ) + $item->to_array() );
		}
	}

	public function save( FulfillmentItem $item ): void {
		$this->rows[ $item->id() ] = $item;
	}
}
