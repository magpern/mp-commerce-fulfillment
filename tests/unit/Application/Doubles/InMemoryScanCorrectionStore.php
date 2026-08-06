<?php
/**
 * In-memory scan correction store for unit tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Doubles;

use MPCF\Domain\Scan\ScanCorrectionStore;

/**
 * Array-backed correction memory.
 */
final class InMemoryScanCorrectionStore implements ScanCorrectionStore {

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = array();

	/**
	 * @param int                  $user_id        Operator user id.
	 * @param int                  $fulfillment_id Fulfillment id.
	 * @param array<string, mixed> $entry          Correction entry.
	 */
	public function remember( int $user_id, int $fulfillment_id, array $entry ): void {
		$this->rows[ $this->key( $user_id, $fulfillment_id ) ] = $entry;
	}

	/**
	 * @param int $user_id        Operator user id.
	 * @param int $fulfillment_id Fulfillment id.
	 * @return array<string, mixed>|null
	 */
	public function pull( int $user_id, int $fulfillment_id ): ?array {
		return $this->rows[ $this->key( $user_id, $fulfillment_id ) ] ?? null;
	}

	/**
	 * @param int $user_id        Operator user id.
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function clear( int $user_id, int $fulfillment_id ): void {
		unset( $this->rows[ $this->key( $user_id, $fulfillment_id ) ] );
	}

	/**
	 * @param int $user_id        Operator user id.
	 * @param int $fulfillment_id Fulfillment id.
	 */
	private function key( int $user_id, int $fulfillment_id ): string {
		return $user_id . ':' . $fulfillment_id;
	}
}
