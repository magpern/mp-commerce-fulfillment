<?php
/**
 * One package-photo card for the Fulfillment Detail CS gallery.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use DateTimeImmutable;

/**
 * Safe metadata only — no filesystem paths, no raw SHA by default.
 */
final class PackagePhotoEvidence {

	/**
	 * Photo id.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Package id.
	 *
	 * @var int
	 */
	private int $package_id;

	/**
	 * Package sequence within the shipment.
	 *
	 * @var int
	 */
	private int $package_seq;

	/**
	 * Stored kind (`contents` or `package`).
	 *
	 * @var string
	 */
	private string $kind;

	/**
	 * Capture time.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $created_at;

	/**
	 * Capturing user id, if any.
	 *
	 * @var int|null
	 */
	private ?int $captured_by;

	/**
	 * Whether bytes were purged by retention.
	 *
	 * @var bool
	 */
	private bool $purged;

	/**
	 * Whether streaming is still available.
	 *
	 * @var bool
	 */
	private bool $has_bytes;

	/**
	 * Purge time when purged.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $purged_at;

	/**
	 * Builds one CS gallery evidence card.
	 *
	 * @param int                    $id          Photo id.
	 * @param int                    $package_id  Package id.
	 * @param int                    $package_seq Package sequence within shipment.
	 * @param string                 $kind        contents|package.
	 * @param DateTimeImmutable      $created_at  Capture time.
	 * @param int|null               $captured_by Capturing user id.
	 * @param bool                   $purged      Whether bytes were purged.
	 * @param bool                   $has_bytes   Whether streaming is available.
	 * @param DateTimeImmutable|null $purged_at   Purge time when purged.
	 */
	public function __construct(
		int $id,
		int $package_id,
		int $package_seq,
		string $kind,
		DateTimeImmutable $created_at,
		?int $captured_by,
		bool $purged,
		bool $has_bytes,
		?DateTimeImmutable $purged_at
	) {
		$this->id          = $id;
		$this->package_id  = $package_id;
		$this->package_seq = $package_seq;
		$this->kind        = $kind;
		$this->created_at  = $created_at;
		$this->captured_by = $captured_by;
		$this->purged      = $purged;
		$this->has_bytes   = $has_bytes;
		$this->purged_at   = $purged_at;
	}

	/**
	 * Photo id.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Package id.
	 */
	public function package_id(): int {
		return $this->package_id;
	}

	/**
	 * Package sequence within the shipment.
	 */
	public function package_seq(): int {
		return $this->package_seq;
	}

	/**
	 * Stored kind.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Capture time.
	 */
	public function created_at(): DateTimeImmutable {
		return $this->created_at;
	}

	/**
	 * Capturing user id, if any.
	 */
	public function captured_by(): ?int {
		return $this->captured_by;
	}

	/**
	 * Whether bytes were purged by retention.
	 */
	public function is_purged(): bool {
		return $this->purged;
	}

	/**
	 * Whether streaming is still available.
	 */
	public function has_bytes(): bool {
		return $this->has_bytes;
	}

	/**
	 * Purge time when purged.
	 */
	public function purged_at(): ?DateTimeImmutable {
		return $this->purged_at;
	}
}
