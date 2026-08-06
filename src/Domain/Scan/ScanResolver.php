<?php
/**
 * Resolves a scan payload against one fulfillment's line items.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Scan;

use MPCF\Domain\Barcode\BarcodePayload;
use MPCF\Domain\FulfillmentItem;

/**
 * Architecture Plan Part IX.3 — pure, deterministic, no I/O.
 */
final class ScanResolver {

	/**
	 * Resolves `$raw` against `$items` belonging to `$fulfillment_id`.
	 *
	 * @param string                      $raw            Raw scan string.
	 * @param array<int, FulfillmentItem> $items        Current fulfillment lines.
	 * @param int                         $fulfillment_id Current fulfillment id.
	 */
	public function resolve( string $raw, array $items, int $fulfillment_id ): ScanResolution {
		$parsed = BarcodePayload::parse( $raw );

		if ( $parsed->is_empty() ) {
			return ScanResolution::rejected( 'empty_payload', 'Scan was empty.' );
		}

		if ( $parsed->is_malformed() ) {
			return ScanResolution::rejected(
				'malformed_payload',
				'Barcode payload is not a valid MPCF code.',
				null
			);
		}

		if ( $parsed->is_ok() ) {
			return $this->resolve_namespaced( $parsed->payload(), $items, $fulfillment_id );
		}

		return $this->resolve_sku( (string) $parsed->plain(), $items );
	}

	/**
	 * Resolves a namespaced MPCF payload.
	 *
	 * @param BarcodePayload              $payload        Parsed payload.
	 * @param array<int, FulfillmentItem> $items          Lines.
	 * @param int                         $fulfillment_id Current fulfillment.
	 */
	private function resolve_namespaced( BarcodePayload $payload, array $items, int $fulfillment_id ): ScanResolution {
		switch ( $payload->type() ) {
			case BarcodePayload::TYPE_FULFILLMENT:
				if ( $payload->value() !== $fulfillment_id ) {
					return ScanResolution::rejected(
						'wrong_fulfillment',
						'This barcode belongs to a different fulfillment.',
						$payload
					);
				}

				return ScanResolution::for_fulfillment( $payload->value(), $payload );

			case BarcodePayload::TYPE_PACKAGE:
				return ScanResolution::for_package( $payload->value(), $payload );

			case BarcodePayload::TYPE_ITEM:
				return $this->match_one(
					$items,
					static fn( FulfillmentItem $item ): bool => (int) $item->id() === $payload->value(),
					$payload,
					'item_not_on_fulfillment',
					'That item is not on this fulfillment.'
				);

			case BarcodePayload::TYPE_VARIATION:
				return $this->match_one(
					$items,
					static fn( FulfillmentItem $item ): bool => $item->variation_id() === $payload->value(),
					$payload,
					'item_not_on_fulfillment',
					'That variation is not on this fulfillment.'
				);

			case BarcodePayload::TYPE_PRODUCT:
				return $this->resolve_product( $payload, $items );

			default:
				return ScanResolution::rejected( 'malformed_payload', 'Unknown barcode type.', $payload );
		}
	}

	/**
	 * Resolves an MPCF:PR product payload.
	 *
	 * @param BarcodePayload              $payload Parsed product payload.
	 * @param array<int, FulfillmentItem> $items   Lines.
	 */
	private function resolve_product( BarcodePayload $payload, array $items ): ScanResolution {
		$matches = array_values(
			array_filter(
				$items,
				static fn( FulfillmentItem $item ): bool => $item->product_id() === $payload->value()
			)
		);

		if ( array() === $matches ) {
			return ScanResolution::rejected(
				'item_not_on_fulfillment',
				'That product is not on this fulfillment.',
				$payload
			);
		}

		$simple = array_values(
			array_filter(
				$matches,
				static fn( FulfillmentItem $item ): bool => 0 === $item->variation_id()
			)
		);

		$variations = array_values(
			array_filter(
				$matches,
				static fn( FulfillmentItem $item ): bool => $item->variation_id() > 0
			)
		);

		if ( array() !== $variations && array() === $simple ) {
			return ScanResolution::rejected(
				'variation_required',
				'Scan a variation barcode or SKU for this product.',
				$payload
			);
		}

		if ( count( $simple ) > 1 || ( 1 === count( $simple ) && array() !== $variations ) ) {
			return ScanResolution::rejected(
				'ambiguous_sku',
				'More than one line matches this product barcode.',
				$payload
			);
		}

		if ( 1 === count( $simple ) ) {
			return ScanResolution::for_item( $simple[0], 'mpcf_payload', $payload );
		}

		if ( count( $matches ) > 1 ) {
			return ScanResolution::rejected(
				'ambiguous_sku',
				'More than one line matches this product barcode.',
				$payload
			);
		}

		return ScanResolution::for_item( $matches[0], 'mpcf_payload', $payload );
	}

	/**
	 * Exact case-sensitive SKU match on sku_snapshot.
	 *
	 * @param string                      $sku   Plain SKU text.
	 * @param array<int, FulfillmentItem> $items Lines.
	 */
	private function resolve_sku( string $sku, array $items ): ScanResolution {
		$matches = array_values(
			array_filter(
				$items,
				static fn( FulfillmentItem $item ): bool => $item->sku_snapshot() === $sku
			)
		);

		if ( array() === $matches ) {
			return ScanResolution::rejected( 'unknown_barcode', 'No item on this fulfillment matches that barcode.' );
		}

		if ( count( $matches ) > 1 ) {
			return ScanResolution::rejected( 'ambiguous_sku', 'More than one line shares that SKU.' );
		}

		return ScanResolution::for_item( $matches[0], 'sku', null );
	}

	/**
	 * Returns the single matching line or a rejection.
	 *
	 * @param array<int, FulfillmentItem>    $items           Lines.
	 * @param callable(FulfillmentItem):bool $predicate       Match predicate.
	 * @param BarcodePayload                 $payload         Parsed payload.
	 * @param string                         $missing_code    Code when zero matches.
	 * @param string                         $missing_message Message when zero matches.
	 */
	private function match_one(
		array $items,
		callable $predicate,
		BarcodePayload $payload,
		string $missing_code,
		string $missing_message
	): ScanResolution {
		$matches = array_values( array_filter( $items, $predicate ) );

		if ( array() === $matches ) {
			return ScanResolution::rejected( $missing_code, $missing_message, $payload );
		}

		if ( count( $matches ) > 1 ) {
			return ScanResolution::rejected(
				'ambiguous_sku',
				'More than one line matches this barcode.',
				$payload
			);
		}

		return ScanResolution::for_item( $matches[0], 'mpcf_payload', $payload );
	}
}
