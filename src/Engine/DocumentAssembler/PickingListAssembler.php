<?php
/**
 * Assembles a picking list's pure document data.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\DocumentAssembler;

use MPCF\Domain\Document\DocumentModel;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\OrderSnapshot;

/**
 * M4-B picking list assembler. Preserves fulfillment-line order (no
 * location-based sort — location_snapshot is display-only and must not
 * create inventory ownership coupling). No supplier, receiving, PO, or
 * carrier data.
 */
final class PickingListAssembler {

	/**
	 * This assembler's document-type registry key.
	 */
	public const DOC_TYPE = 'picking_list';

	/**
	 * Bundled picking-list template version.
	 */
	public const TEMPLATE_VERSION = '1';

	/**
	 * Assembles a picking list document model.
	 *
	 * @param Fulfillment                 $fulfillment Fulfillment being picked.
	 * @param OrderSnapshot               $order       Live order read (customer note only when relevant).
	 * @param array<int, FulfillmentItem> $items       Line-item snapshots in repository order.
	 * @param string                      $store_name  Store display name.
	 * @param array<string, mixed>        $branding    Branding snapshot.
	 * @param string                      $template_version Explicit template version.
	 */
	public static function assemble(
		Fulfillment $fulfillment,
		OrderSnapshot $order,
		array $items,
		string $store_name,
		array $branding = array(),
		string $template_version = self::TEMPLATE_VERSION
	): DocumentModel {
		if ( array() === $branding ) {
			$branding = array( 'store_name' => $store_name );
		}

		$note = $order->customer_note();

		return new DocumentModel(
			self::DOC_TYPE,
			(int) $fulfillment->id(),
			$fulfillment->order_number_snapshot(),
			$fulfillment->customer_name_snapshot(),
			$order->ship_to_lines(),
			$store_name,
			array_map( array( self::class, 'item_line' ), array_values( $items ) ),
			array(),
			$fulfillment->order_number_snapshot(),
			$fulfillment->state(),
			$template_version,
			$branding,
			null,
			0,
			$note
		);
	}

	/**
	 * One picking-list line.
	 *
	 * @param FulfillmentItem $item Item snapshot.
	 * @return array{
	 *     sku: string,
	 *     name: string,
	 *     qty_ordered: int,
	 *     qty_to_pick: int,
	 *     qty_picked: int,
	 *     qty_remaining: int,
	 *     location_snapshot: string|null
	 * }
	 */
	private static function item_line( FulfillmentItem $item ): array {
		$ordered   = $item->qty_ordered();
		$picked    = $item->qty_picked();
		$remaining = max( 0, $ordered - $picked );

		return array(
			'sku'               => $item->sku_snapshot(),
			'name'              => $item->name_snapshot(),
			'qty_ordered'       => $ordered,
			'qty_to_pick'       => $ordered,
			'qty_picked'        => $picked,
			'qty_remaining'     => $remaining,
			'location_snapshot' => $item->location_snapshot(),
		);
	}
}
