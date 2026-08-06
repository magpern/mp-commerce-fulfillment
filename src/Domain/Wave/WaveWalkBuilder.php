<?php
/**
 * Pure warehouse-walk model builder for a wave.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Wave;

use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;

/**
 * Architecture Plan Part X.3 — groups by location + product key, sorts
 * location_snapshot NULLS LAST then SKU. Variations never collapse into
 * parent SKU rows. Duplicate SKUs across orders become one walk row with
 * FIFO-ordered allocations.
 */
final class WaveWalkBuilder {

	/**
	 * Builds the walk model from member fulfillments and their items.
	 *
	 * @param array<int, Fulfillment>                 $fulfillments Fulfillments keyed by id.
	 * @param array<int, array<int, FulfillmentItem>> $items_by_fid Items keyed by fulfillment id.
	 * @return array{
	 *     rows: list<array<string, mixed>>,
	 *     remaining_lines: int,
	 *     remaining_qty: int,
	 *     completed_fulfillments: int,
	 *     remaining_fulfillments: int
	 * }
	 */
	public function build( array $fulfillments, array $items_by_fid ): array {
		$groups = array();

		foreach ( $items_by_fid as $fulfillment_id => $items ) {
			$fulfillment = $fulfillments[ (int) $fulfillment_id ] ?? null;

			if ( null === $fulfillment ) {
				continue;
			}

			foreach ( $items as $item ) {
				$outstanding = max( 0, $item->qty_ordered() - $item->qty_picked() );
				$key         = $this->group_key( $item );

				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'location_snapshot' => $item->location_snapshot(),
						'sku_snapshot'      => $item->sku_snapshot(),
						'name_snapshot'     => $item->name_snapshot(),
						'product_id'        => $item->product_id(),
						'variation_id'      => $item->variation_id(),
						'product_key'       => $this->product_key( $item ),
						'required_qty'      => 0,
						'done_qty'          => 0,
						'allocations'       => array(),
					);
				}

				$groups[ $key ]['required_qty'] += $outstanding;
				$groups[ $key ]['done_qty']     += $item->qty_picked();
				$groups[ $key ]['allocations'][] = array(
					'fulfillment_id' => (int) $fulfillment_id,
					'item_id'        => (int) $item->id(),
					'outstanding'    => $outstanding,
					'qty_ordered'    => $item->qty_ordered(),
					'qty_picked'     => $item->qty_picked(),
					'created_at'     => $fulfillment->created_at()->format( 'Y-m-d H:i:s' ),
					'order_number'   => $fulfillment->order_number_snapshot(),
				);
			}
		}

		$rows = array_values( $groups );

		foreach ( $rows as &$row ) {
			usort(
				$row['allocations'],
				static function ( array $a, array $b ): int {
					$cmp = strcmp( (string) $a['created_at'], (string) $b['created_at'] );

					if ( 0 !== $cmp ) {
						return $cmp;
					}

					return (int) $a['item_id'] <=> (int) $b['item_id'];
				}
			);

			$row['complete'] = 0 === (int) $row['required_qty'];
			unset( $row['product_key'] );
		}
		unset( $row );

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$loc_a   = $a['location_snapshot'];
				$loc_b   = $b['location_snapshot'];
				$empty_a = null === $loc_a || '' === $loc_a;
				$empty_b = null === $loc_b || '' === $loc_b;

				if ( $empty_a !== $empty_b ) {
					return $empty_a ? 1 : -1;
				}

				if ( ! $empty_a && ! $empty_b ) {
					$cmp = strcmp( (string) $loc_a, (string) $loc_b );
					if ( 0 !== $cmp ) {
						return $cmp;
					}
				}

				$cmp = strcmp( (string) $a['sku_snapshot'], (string) $b['sku_snapshot'] );
				if ( 0 !== $cmp ) {
					return $cmp;
				}

				$cmp = (int) $a['product_id'] <=> (int) $b['product_id'];
				if ( 0 !== $cmp ) {
					return $cmp;
				}

				return (int) $a['variation_id'] <=> (int) $b['variation_id'];
			}
		);

		$remaining_lines = 0;
		$remaining_qty   = 0;

		foreach ( $rows as $row ) {
			if ( (int) $row['required_qty'] > 0 ) {
				++$remaining_lines;
				$remaining_qty += (int) $row['required_qty'];
			}
		}

		$completed = 0;
		$remaining = 0;

		foreach ( $fulfillments as $fulfillment ) {
			$fid   = (int) $fulfillment->id();
			$items = $items_by_fid[ $fid ] ?? array();
			$done  = true;

			foreach ( $items as $item ) {
				if ( $item->qty_picked() < $item->qty_ordered() ) {
					$done = false;
					break;
				}
			}

			if ( $done && array() !== $items ) {
				++$completed;
			} else {
				++$remaining;
			}
		}

		return array(
			'rows'                   => $rows,
			'remaining_lines'        => $remaining_lines,
			'remaining_qty'          => $remaining_qty,
			'completed_fulfillments' => $completed,
			'remaining_fulfillments' => $remaining,
		);
	}

	/**
	 * Group key: location + product identity (variation never collapses).
	 *
	 * @param FulfillmentItem $item Line item.
	 */
	private function group_key( FulfillmentItem $item ): string {
		$location = $item->location_snapshot();
		$loc_key  = null === $location || '' === $location ? "\0" : $location;

		return $loc_key . '|' . $this->product_key( $item );
	}

	/**
	 * Product key: V:{id} when variation, else PR:{id}|SKU:{sku}.
	 *
	 * @param FulfillmentItem $item Line item.
	 */
	private function product_key( FulfillmentItem $item ): string {
		if ( $item->variation_id() > 0 ) {
			return 'V:' . $item->variation_id();
		}

		return 'PR:' . $item->product_id() . '|SKU:' . $item->sku_snapshot();
	}
}
