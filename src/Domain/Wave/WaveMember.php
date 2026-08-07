<?php
/**
 * One fulfillment membership in a wave.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Wave;

use DateTimeImmutable;

/**
 * Architecture Plan Part X.2 — wave membership (no quantity duplication).
 */
final class WaveMember {

	/**
	 * Owning wave id.
	 *
	 * @var int
	 */
	private int $wave_id;

	/**
	 * Member fulfillment id.
	 *
	 * @var int
	 */
	private int $fulfillment_id;

	/**
	 * Stable walk / display order.
	 *
	 * @var int
	 */
	private int $position;

	/**
	 * When this fulfillment joined the wave.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $joined_at;

	/**
	 * When the fulfillment reached `picked` under this wave, or null.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $picked_at;

	/**
	 * Assembles a member. Use {@see create()} / {@see from_array()}.
	 *
	 * @param int                    $wave_id        Wave id.
	 * @param int                    $fulfillment_id Fulfillment id.
	 * @param int                    $position       Display order.
	 * @param DateTimeImmutable      $joined_at      Join time.
	 * @param DateTimeImmutable|null $picked_at      Picked time.
	 */
	private function __construct(
		int $wave_id,
		int $fulfillment_id,
		int $position,
		DateTimeImmutable $joined_at,
		?DateTimeImmutable $picked_at
	) {
		$this->wave_id        = $wave_id;
		$this->fulfillment_id = $fulfillment_id;
		$this->position       = $position;
		$this->joined_at      = $joined_at;
		$this->picked_at      = $picked_at;
	}

	/**
	 * Builds a new membership row.
	 *
	 * @param int               $wave_id        Wave id.
	 * @param int               $fulfillment_id Fulfillment id.
	 * @param int               $position       Display order.
	 * @param DateTimeImmutable $now            Join time.
	 */
	public static function create( int $wave_id, int $fulfillment_id, int $position, DateTimeImmutable $now ): self {
		return new self( $wave_id, $fulfillment_id, $position, $now, null );
	}

	/**
	 * Rebuilds from array shape.
	 *
	 * @param array<string, mixed> $data Array shape from {@see to_array()}.
	 */
	public static function from_array( array $data ): self {
		$picked = $data['picked_at'] ?? null;

		return new self(
			(int) $data['wave_id'],
			(int) $data['fulfillment_id'],
			(int) $data['position'],
			$data['joined_at'] instanceof DateTimeImmutable
				? $data['joined_at']
				: new DateTimeImmutable( (string) $data['joined_at'] ),
			null === $picked || '' === $picked
				? null
				: ( $picked instanceof DateTimeImmutable ? $picked : new DateTimeImmutable( (string) $picked ) )
		);
	}

	/**
	 * Array shape for persistence / tests.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'wave_id'        => $this->wave_id,
			'fulfillment_id' => $this->fulfillment_id,
			'position'       => $this->position,
			'joined_at'      => $this->joined_at,
			'picked_at'      => $this->picked_at,
		);
	}

	/**
	 * Owning wave id.
	 */
	public function wave_id(): int {
		return $this->wave_id;
	}

	/**
	 * Member fulfillment id.
	 */
	public function fulfillment_id(): int {
		return $this->fulfillment_id;
	}

	/**
	 * Stable walk / display order.
	 */
	public function position(): int {
		return $this->position;
	}

	/**
	 * When this fulfillment joined the wave.
	 */
	public function joined_at(): DateTimeImmutable {
		return $this->joined_at;
	}

	/**
	 * When the fulfillment reached `picked` under this wave, or null.
	 */
	public function picked_at(): ?DateTimeImmutable {
		return $this->picked_at;
	}

	/**
	 * Whether this member has been marked picked under the wave.
	 */
	public function is_picked(): bool {
		return null !== $this->picked_at;
	}

	/**
	 * Marks the member as picked at `$now`.
	 *
	 * @param DateTimeImmutable $now Picked timestamp.
	 */
	public function mark_picked( DateTimeImmutable $now ): void {
		$this->picked_at = $now;
	}

	/**
	 * Rebinds this membership to a newly assigned wave id (post-insert).
	 *
	 * @param int $wave_id Persisted wave id.
	 */
	public function bind_wave_id( int $wave_id ): void {
		$this->wave_id = $wave_id;
	}
}
