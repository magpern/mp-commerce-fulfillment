<?php
/**
 * Operator-facing labels for document audit events.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Documents;

/**
 * Keeps raw event type strings out of normal operator UX.
 */
final class DocumentEventLabels {

	/**
	 * Human label for a document-related event, or null when not document-related.
	 *
	 * @param string               $event_type Event type identifier.
	 * @param array<string, mixed> $payload    Event payload.
	 */
	public static function describe( string $event_type, array $payload = array() ): ?string {
		$doc_type = isset( $payload['doc_type'] ) ? (string) $payload['doc_type'] : '';
		$label    = self::type_label( $doc_type );

		if ( 'document.rendered' === $event_type ) {
			return sprintf(
				/* translators: %s: document type label */
				__( '%s printed.', 'mp-commerce-fulfillment' ),
				$label
			);
		}

		if ( 'document.reprinted' === $event_type ) {
			return sprintf(
				/* translators: %s: document type label */
				__( '%s reprinted.', 'mp-commerce-fulfillment' ),
				$label
			);
		}

		return null;
	}

	/**
	 * Display name for a document type key.
	 *
	 * @param string $doc_type Registry key.
	 */
	public static function type_label( string $doc_type ): string {
		switch ( $doc_type ) {
			case 'packing_slip':
				return __( 'Packing slip', 'mp-commerce-fulfillment' );
			case 'picking_list':
				return __( 'Picking list', 'mp-commerce-fulfillment' );
			default:
				return '' !== $doc_type ? $doc_type : __( 'Document', 'mp-commerce-fulfillment' );
		}
	}
}
