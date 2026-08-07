<?php
/**
 * Hash-chain verification for fulfillments (audit verify).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Diagnostics;

use MPCF\Domain\Event\Canonicalizer;
use MPCF\Domain\Repository\EventRepository;

/**
 * Read-only walk of per-fulfillment hash chains.
 */
final class AuditChainVerifier {

	/**
	 * Builds the chain verifier.
	 *
	 * @param EventRepository $events Event store.
	 */
	public function __construct(
		private EventRepository $events
	) {
	}

	/**
	 * Verifies one fulfillment's chain.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @return array{ok:bool,fulfillment_id:int,events:int,error:?string}
	 */
	public function verify_fulfillment( int $fulfillment_id ): array {
		$timeline      = $this->events->timeline_for_fulfillment( $fulfillment_id );
		$expected_prev = null;
		$n             = 0;

		foreach ( $timeline as $row ) {
			++$n;
			$stored_prev = isset( $row['prev_hash'] ) && null !== $row['prev_hash'] && '' !== $row['prev_hash']
				? (string) $row['prev_hash']
				: null;
			$stored_hash = (string) ( $row['hash'] ?? '' );
			$payload     = is_array( $row['payload'] ?? null ) ? $row['payload'] : array();

			if ( $stored_prev !== $expected_prev ) {
				return array(
					'ok'             => false,
					'fulfillment_id' => $fulfillment_id,
					'events'         => $n,
					'error'          => sprintf( 'prev_hash mismatch at event #%d.', $n ),
				);
			}

			$hashable = array_key_exists( 'v', $payload )
				? $payload
				: ( array( 'v' => 1 ) + $payload );
			$computed = Canonicalizer::hash( $stored_prev, $hashable );

			if ( ! hash_equals( $stored_hash, $computed ) ) {
				return array(
					'ok'             => false,
					'fulfillment_id' => $fulfillment_id,
					'events'         => $n,
					'error'          => sprintf( 'hash mismatch at event #%d.', $n ),
				);
			}

			$expected_prev = $stored_hash;
		}

		return array(
			'ok'             => true,
			'fulfillment_id' => $fulfillment_id,
			'events'         => $n,
			'error'          => null,
		);
	}
}
