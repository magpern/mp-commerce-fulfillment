<?php
/**
 * Short-lived last-scan correction memory (no scan-session table).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Scan;

/**
 * Architecture Plan Part IX.10 — per-operator undo of the most recent
 * successful scan for one fulfillment.
 */
interface ScanCorrectionStore {

	/**
	 * Remembers the last successful scan for undo.
	 *
	 * @param int                  $user_id        Operator user id.
	 * @param int                  $fulfillment_id Fulfillment id.
	 * @param array<string, mixed> $entry          Correction entry.
	 */
	public function remember( int $user_id, int $fulfillment_id, array $entry ): void;

	/**
	 * Returns the pending correction entry, or null.
	 *
	 * @param int $user_id        Operator user id.
	 * @param int $fulfillment_id Fulfillment id.
	 * @return array<string, mixed>|null
	 */
	public function pull( int $user_id, int $fulfillment_id ): ?array;

	/**
	 * Clears any pending correction.
	 *
	 * @param int $user_id        Operator user id.
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function clear( int $user_id, int $fulfillment_id ): void;
}
