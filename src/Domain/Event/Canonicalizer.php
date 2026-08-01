<?php
/**
 * Deterministic payload canonicalization and hash-chain computation for the
 * audit log.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Event;

/**
 * Resolves the open design question the architecture document left for
 * this milestone's execution plan: what "canonical" means for
 * `hash = sha256(prev_hash . canonical_payload)`.
 *
 * A payload is canonicalized as UTF-8, slash-unescaped JSON with every
 * associative array's keys sorted recursively — so the same logical event
 * always hashes identically regardless of the PHP array insertion order it
 * happened to be built in, or locale. This is the entire tamper-evidence
 * guarantee: it is cheap (no cryptographic signing), and it is not meant to
 * defeat a hostile server administrator — only to detect casual tampering
 * or database mishaps, exactly as documented for operators in this
 * project's security notes.
 */
final class Canonicalizer {

	/**
	 * The canonical JSON encoding of a payload — deterministic key order at
	 * every nesting level.
	 *
	 * @param array<string, mixed> $payload Event payload.
	 */
	public static function canonical_payload( array $payload ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain must stay platform-free (I6); the platform's own JSON-encoding helper is off-limits here for exactly that reason.
		return (string) json_encode( self::sort_recursive( $payload ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * The next hash in a fulfillment's chain: a function of the previous
	 * event's hash (or null for the first event) and this event's canonical
	 * payload.
	 *
	 * @param string|null          $prev_hash Previous event's hash, or null for the first event in the chain.
	 * @param array<string, mixed> $payload   Event payload.
	 */
	public static function hash( ?string $prev_hash, array $payload ): string {
		return hash( 'sha256', ( $prev_hash ?? '' ) . self::canonical_payload( $payload ) );
	}

	/**
	 * Recursively sorts every associative array's keys.
	 *
	 * @param array<int|string, mixed> $data Payload fragment.
	 * @return array<int|string, mixed>
	 */
	private static function sort_recursive( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::sort_recursive( $value );
			}
		}

		if ( self::is_associative( $data ) ) {
			ksort( $data );
		}

		return $data;
	}

	/**
	 * Whether an array is associative (has non-sequential-integer keys).
	 *
	 * @param array<int|string, mixed> $data Array to inspect.
	 */
	private static function is_associative( array $data ): bool {
		return array_values( $data ) !== $data;
	}
}
