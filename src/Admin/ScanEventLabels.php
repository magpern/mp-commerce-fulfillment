<?php
/**
 * Operator-facing labels for barcode scan audit events.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

/**
 * Keeps raw scan event type strings out of operator UX.
 */
final class ScanEventLabels {

	/**
	 * Human label for a scan event, or null when unrelated.
	 *
	 * @param string               $event_type Event type.
	 * @param array<string, mixed> $payload    Payload.
	 */
	public static function describe( string $event_type, array $payload = array() ): ?string {
		$item_id = isset( $payload['item_id'] ) ? (int) $payload['item_id'] : 0;
		$mode    = isset( $payload['mode'] ) ? (string) $payload['mode'] : '';

		switch ( $event_type ) {
			case 'scan.item_picked':
				$qty = isset( $payload['qty_picked'] ) ? (int) $payload['qty_picked'] : 0;

				return sprintf(
					/* translators: 1: fulfillment item id, 2: resulting picked quantity */
					__( 'Scanned pick for item %1$d (picked %2$d).', 'mp-commerce-fulfillment' ),
					$item_id,
					$qty
				);

			case 'scan.item_packed':
				$qty = isset( $payload['qty_packed'] ) ? (int) $payload['qty_packed'] : 0;

				return sprintf(
					/* translators: 1: fulfillment item id, 2: resulting packed quantity */
					__( 'Scanned pack for item %1$d (packed %2$d).', 'mp-commerce-fulfillment' ),
					$item_id,
					$qty
				);

			case 'scan.corrected':
				return sprintf(
					/* translators: 1: scan mode (picking|packing), 2: fulfillment item id */
					__( 'Undid last %1$s scan for item %2$d.', 'mp-commerce-fulfillment' ),
					'' !== $mode ? $mode : __( 'scan', 'mp-commerce-fulfillment' ),
					$item_id
				);

			default:
				return null;
		}
	}
}
