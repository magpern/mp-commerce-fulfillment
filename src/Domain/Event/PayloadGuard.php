<?php
/**
 * Mechanical guard against PII entering an audit-event payload.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Event;

use InvalidArgumentException;

/**
 * Resolves the open design question the architecture document left for
 * this milestone (spike S6): payloads reference ids, never copy
 * addresses/emails. This is not merely a test of production code — it is
 * called from {@see DomainEvent}'s own factories, so no `DomainEvent` can
 * be constructed with an unsafe payload in the first place. That is the
 * whole point: make accidental leakage mechanically difficult (impossible
 * to construct the object) as well as mechanically detectable (a loud,
 * immediate exception naming exactly what tripped it, never a silent
 * pass-through).
 *
 * Two independent checks, because a key-name denylist alone would miss an
 * email pasted under an innocuous key, and a value-pattern check alone
 * would miss a field simply named `customer_email` with an empty or
 * templated value:
 *
 * - every key, at every nesting level, is checked against a denylist of
 *   PII-shaped substrings;
 * - every string value, at every nesting level, is checked against an
 *   email-address pattern.
 *
 * Deliberately not exhaustive (no phone-number pattern, no address
 * heuristics beyond the key denylist) — the goal is to catch the
 * structured, systematic copying this milestone's payloads are actually at
 * risk of (a developer reaching for `$order->get_billing_email()` while
 * building a payload array), not to build a general-purpose PII scanner.
 */
final class PayloadGuard {

	/**
	 * Substrings that must never appear in a payload key, at any nesting
	 * level, case-insensitively.
	 *
	 * @var array<int, string>
	 */
	private const FORBIDDEN_KEY_MARKERS = array(
		'email',
		'address',
		'phone',
		'ssn',
		'dob',
		'birth',
		'street',
		'city',
		'zip',
		'postal',
		'card_number',
		'cvv',
	);

	/**
	 * Throws if a payload contains a PII-shaped key or an email-shaped
	 * string value, at any nesting level.
	 *
	 * @param array<string, mixed> $payload Payload to check.
	 * @throws InvalidArgumentException When the payload looks unsafe.
	 */
	public static function assert_safe( array $payload ): void {
		self::scan( $payload, '' );
	}

	/**
	 * Recursively checks every key and value in a payload fragment.
	 *
	 * @param array<int|string, mixed> $payload Payload fragment to check.
	 * @param string                   $path    Dotted key path, for the exception message.
	 * @throws InvalidArgumentException When the payload looks unsafe.
	 */
	private static function scan( array $payload, string $path ): void {
		foreach ( $payload as $key => $value ) {
			$key_path = '' === $path ? (string) $key : $path . '.' . $key;

			if ( is_string( $key ) ) {
				self::assert_key_is_safe( $key, $key_path );
			}

			if ( is_array( $value ) ) {
				self::scan( $value, $key_path );
				continue;
			}

			if ( is_string( $value ) && self::looks_like_email( $value ) ) {
				throw new InvalidArgumentException( "Event payload value at \"{$key_path}\" looks like an email address — reference an id instead." );
			}
		}
	}

	/**
	 * Checks one key against the denylist.
	 *
	 * @param string $key      Key to check.
	 * @param string $key_path Dotted key path, for the exception message.
	 * @throws InvalidArgumentException When the key looks unsafe.
	 */
	private static function assert_key_is_safe( string $key, string $key_path ): void {
		foreach ( self::FORBIDDEN_KEY_MARKERS as $marker ) {
			if ( false !== stripos( $key, $marker ) ) {
				throw new InvalidArgumentException( "Event payload key \"{$key_path}\" looks like PII (matches \"{$marker}\") — reference an id instead." );
			}
		}
	}

	/**
	 * Whether a value contains an email-shaped substring anywhere in it —
	 * not only whether the value is *entirely* an email address, since a
	 * free-text field (a transition reason, an operator note) is exactly
	 * where an email could be pasted alongside other words.
	 *
	 * @param string $value Value to check.
	 */
	private static function looks_like_email( string $value ): bool {
		return 1 === preg_match( '/[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}/', $value );
	}
}
