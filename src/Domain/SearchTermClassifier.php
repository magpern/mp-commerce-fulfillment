<?php
/**
 * Classifies a free-text Queue search term into a lookup strategy.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

/**
 * Pure classification logic (invariant I6) behind {@see SearchQuery}'s v1
 * implementation, per Architecture Plan §9.3 ("Search architecture"):
 * numeric -> order/fulfillment identifiers, SKU-shaped -> item lookup,
 * otherwise -> the customer-name snapshot. Tracking-shaped terms are
 * deliberately not classified as their own case: M1 has no
 * shipments/packages table for a tracking lookup to query, and the
 * approved contract permits that to remain unsupported until M2 — the
 * simplest way to "permit an empty result path without schema work" is to
 * let a tracking-shaped string simply fall through to the name-snapshot
 * lookup (which will correctly find nothing) rather than inventing a
 * classification branch with no data source behind it yet.
 */
final class SearchTermClassifier {

	public const NUMERIC = 'numeric';
	public const SKU     = 'sku';
	public const NAME    = 'name';

	/**
	 * Classifies a search term. Never called with an empty term — the
	 * caller skips search entirely in that case.
	 *
	 * @param string $term Raw search term.
	 */
	public static function classify( string $term ): string {
		if ( ctype_digit( $term ) ) {
			return self::NUMERIC;
		}

		if ( self::is_sku_shaped( $term ) ) {
			return self::SKU;
		}

		return self::NAME;
	}

	/**
	 * A SKU-shaped term: alphanumeric (optionally hyphen/underscore
	 * separated), containing at least one letter and one digit — matches
	 * common SKU conventions ("SKU-123", "ABC123") while a plain word like
	 * a customer's first name ("Jane") falls through to the name lookup.
	 *
	 * @param string $term Raw search term.
	 */
	private static function is_sku_shaped( string $term ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9]+(?:[-_][A-Za-z0-9]+)*$/', $term )
			&& 1 === preg_match( '/[0-9]/', $term )
			&& 1 === preg_match( '/[A-Za-z]/', $term );
	}
}
