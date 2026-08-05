<?php
/**
 * Assembles a packing slip's pure document data.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\DocumentAssembler;

use MPCF\Domain\Document\DocumentModel;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\OrderSnapshot;
use MPCF\Domain\Shipping\Package;

/**
 * Architecture Plan §10/§IV.7: "assembly != rendering" — this class
 * produces a {@see DocumentModel} and nothing else. No markup, no
 * platform-integration symbol, no direct database access; exhaustively
 * unit-testable from plain constructed objects.
 *
 * `order_number`/`customer_name` come from `$fulfillment`'s own snapshot
 * fields, not `$order` — stable even if the order is later renamed or its
 * customer name changes, matching why the snapshot exists at all. Only
 * `ship_to_lines` comes from `$order`: a ship-to address is not one of the
 * fields `Fulfillment::intake()` persists, so a live read is the only
 * source for it. `$store_name` is plain data the caller resolves (from
 * site identity) — this class never touches a WordPress function to get
 * it itself (invariant I6).
 */
final class PackingSlipAssembler {

	/**
	 * This assembler's document-type registry key.
	 */
	public const DOC_TYPE = 'packing_slip';

	/**
	 * Assembles a packing slip's document model.
	 *
	 * @param Fulfillment                 $fulfillment Fulfillment being packed.
	 * @param OrderSnapshot               $order       A live read of the owning order — used only for its address.
	 * @param array<int, FulfillmentItem> $items       The fulfillment's own line-item snapshots.
	 * @param array<int, Package>         $packages    The fulfillment's packages (via its shipments).
	 * @param string                      $store_name  Store display name.
	 */
	public static function assemble( Fulfillment $fulfillment, OrderSnapshot $order, array $items, array $packages, string $store_name ): DocumentModel {
		return new DocumentModel(
			self::DOC_TYPE,
			(int) $fulfillment->id(),
			$fulfillment->order_number_snapshot(),
			$fulfillment->customer_name_snapshot(),
			$order->ship_to_lines(),
			$store_name,
			array_map( array( self::class, 'item_line' ), $items ),
			array_map( array( self::class, 'package_summary' ), $packages ),
			$fulfillment->order_number_snapshot(),
			$fulfillment->state(),
			'1',
			array( 'store_name' => $store_name )
		);
	}

	/**
	 * One line item's document-model shape.
	 *
	 * @param FulfillmentItem $item Item to summarize.
	 * @return array{sku: string, name: string, qty_ordered: int}
	 */
	private static function item_line( FulfillmentItem $item ): array {
		return array(
			'sku'         => $item->sku_snapshot(),
			'name'        => $item->name_snapshot(),
			'qty_ordered' => $item->qty_ordered(),
		);
	}

	/**
	 * One package's document-model shape.
	 *
	 * @param Package $package Package to summarize.
	 * @return array{seq: int, weight_grams: int|null, length_mm: int|null, width_mm: int|null, height_mm: int|null, tracking_number: string|null}
	 */
	private static function package_summary( Package $package ): array {
		$spec = $package->spec();

		return array(
			'seq'             => $package->seq(),
			'weight_grams'    => $spec->weight_grams(),
			'length_mm'       => $spec->length_mm(),
			'width_mm'        => $spec->width_mm(),
			'height_mm'       => $spec->height_mm(),
			'tracking_number' => $package->tracking_number(),
		);
	}
}
