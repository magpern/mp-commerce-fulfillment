<?php
/**
 * WordPress transient-backed scan correction store.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Scan;

use MPCF\Domain\Scan\ScanCorrectionStore;

/**
 * TTL matches Part IX.10 (~30 minutes). Keys are per user + fulfillment.
 */
final class TransientScanCorrectionStore implements ScanCorrectionStore {

	private const TTL_SECONDS = 1800;

	/**
	 * Remembers the last successful scan for undo.
	 *
	 * @param int                  $user_id        Operator user id.
	 * @param int                  $fulfillment_id Fulfillment id.
	 * @param array<string, mixed> $entry          Correction entry.
	 */
	public function remember( int $user_id, int $fulfillment_id, array $entry ): void {
		set_transient( $this->key( $user_id, $fulfillment_id ), $entry, self::TTL_SECONDS );
	}

	/**
	 * Returns the pending correction entry, or null.
	 *
	 * @param int $user_id        Operator user id.
	 * @param int $fulfillment_id Fulfillment id.
	 * @return array<string, mixed>|null
	 */
	public function pull( int $user_id, int $fulfillment_id ): ?array {
		$value = get_transient( $this->key( $user_id, $fulfillment_id ) );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Clears any pending correction.
	 *
	 * @param int $user_id        Operator user id.
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function clear( int $user_id, int $fulfillment_id ): void {
		delete_transient( $this->key( $user_id, $fulfillment_id ) );
	}

	/**
	 * Builds the transient key.
	 *
	 * @param int $user_id        Operator user id.
	 * @param int $fulfillment_id Fulfillment id.
	 */
	private function key( int $user_id, int $fulfillment_id ): string {
		return 'mpcf_scan_undo_' . $user_id . '_' . $fulfillment_id;
	}
}
