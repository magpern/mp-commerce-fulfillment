<?php
/**
 * Result of a versioned photo capture or soft-delete.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Photos;

use MPCF\Domain\Media\PhotoRecord;

/**
 * Immutable envelope returned by versioned PhotoService mutations so REST
 * can emit photo metadata, the new fulfillment version, and whether the
 * package-photo packing requirement is satisfied.
 */
final class PhotoMutationResult {

	/**
	 * The captured or soft-deleted photo.
	 *
	 * @var PhotoRecord
	 */
	private PhotoRecord $photo;

	/**
	 * Fulfillment optimistic-lock version after a successful touch, or the
	 * current version when an already-deleted soft-delete is idempotent.
	 *
	 * @var int
	 */
	private int $version;

	/**
	 * Whether the fulfillment has ≥1 active kind=package photo.
	 *
	 * @var bool
	 */
	private bool $requirement_satisfied;

	/**
	 * Builds the result.
	 *
	 * @param PhotoRecord $photo                  Mutated photo.
	 * @param int         $version                Fulfillment version to return to the client.
	 * @param bool        $requirement_satisfied  Package-photo requirement state.
	 */
	public function __construct( PhotoRecord $photo, int $version, bool $requirement_satisfied ) {
		$this->photo                 = $photo;
		$this->version               = $version;
		$this->requirement_satisfied = $requirement_satisfied;
	}

	/**
	 * The mutated photo.
	 */
	public function photo(): PhotoRecord {
		return $this->photo;
	}

	/**
	 * Fulfillment version for the response envelope.
	 */
	public function version(): int {
		return $this->version;
	}

	/**
	 * Whether the package-photo packing requirement is satisfied.
	 */
	public function requirement_satisfied(): bool {
		return $this->requirement_satisfied;
	}
}
