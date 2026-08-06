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
 * TTL matches Part IX.10 (~30 minutes). Keys are per user + fulfillment,
 * or per user + wave for Wave Scan Mode (Part X).
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
	 * Remembers the last successful wave scan for undo.
	 *
	 * @param int                  $user_id Operator user id.
	 * @param int                  $wave_id Wave id.
	 * @param array<string, mixed> $entry   Correction entry.
	 */
	public function remember_wave( int $user_id, int $wave_id, array $entry ): void {
		set_transient( $this->wave_key( $user_id, $wave_id ), $entry, self::TTL_SECONDS );
	}

	/**
	 * Returns the pending wave correction entry, or null.
	 *
	 * @param int $user_id Operator user id.
	 * @param int $wave_id Wave id.
	 * @return array<string, mixed>|null
	 */
	public function pull_wave( int $user_id, int $wave_id ): ?array {
		$value = get_transient( $this->wave_key( $user_id, $wave_id ) );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Clears any pending wave correction.
	 *
	 * @param int $user_id Operator user id.
	 * @param int $wave_id Wave id.
	 */
	public function clear_wave( int $user_id, int $wave_id ): void {
		delete_transient( $this->wave_key( $user_id, $wave_id ) );
	}

	/**
	 * Builds the fulfillment undo transient key.
	 *
	 * @param int $user_id        Operator user id.
	 * @param int $fulfillment_id Fulfillment id.
	 */
	private function key( int $user_id, int $fulfillment_id ): string {
		return 'mpcf_scan_undo_' . $user_id . '_' . $fulfillment_id;
	}

	/**
	 * Builds the wave undo transient key.
	 *
	 * @param int $user_id Operator user id.
	 * @param int $wave_id Wave id.
	 */
	private function wave_key( int $user_id, int $wave_id ): string {
		return 'mpcf_wave_scan_undo_' . $user_id . '_' . $wave_id;
	}
}
