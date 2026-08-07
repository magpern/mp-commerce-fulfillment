<?php
/**
 * Daily metrics snapshot (counters + durations).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\Analytics;

/**
 * Immutable value object for one warehouse × UTC day (or LIVE window).
 */
final class DailyMetrics {

	/**
	 * UTC day key (`Y-m-d`).
	 *
	 * @var string
	 */
	private string $utc_date;

	/**
	 * Warehouse scope for this snapshot.
	 *
	 * @var int
	 */
	private int $warehouse_id;

	/**
	 * Rollup payload format version.
	 *
	 * @var int
	 */
	private int $rollup_version;

	/**
	 * Normalized counter payload.
	 *
	 * @var array<string, mixed>
	 */
	private array $counters;

	/**
	 * Duration stats keyed by hop id.
	 *
	 * @var array<string, array{count: int, sum: float, avg: float|null, p50: float|null, p90: float|null}>
	 */
	private array $durations;

	/**
	 * Highest event id observed while computing this snapshot.
	 *
	 * @var int|null
	 */
	private ?int $source_event_max_id;

	/**
	 * Builds a metrics snapshot.
	 *
	 * @param string                                                                                          $utc_date            UTC day key.
	 * @param int                                                                                             $warehouse_id        Warehouse scope.
	 * @param int                                                                                             $rollup_version      Payload format version.
	 * @param array<string, mixed>                                                                            $counters            Raw or partial counters (normalized).
	 * @param array<string, array{count: int, sum: float, avg: float|null, p50: float|null, p90: float|null}> $durations           Hop duration stats.
	 * @param int|null                                                                                        $source_event_max_id Optional max source event id.
	 */
	public function __construct(
		string $utc_date,
		int $warehouse_id,
		int $rollup_version,
		array $counters,
		array $durations,
		?int $source_event_max_id = null
	) {
		$this->utc_date            = $utc_date;
		$this->warehouse_id        = $warehouse_id;
		$this->rollup_version      = $rollup_version;
		$this->counters            = CounterCalculator::normalize( $counters );
		$this->durations           = array() === $durations ? DurationCalculator::empty() : $durations;
		$this->source_event_max_id = $source_event_max_id;
	}

	/**
	 * UTC day key (`Y-m-d`).
	 */
	public function utc_date(): string {
		return $this->utc_date;
	}

	/**
	 * Warehouse scope.
	 */
	public function warehouse_id(): int {
		return $this->warehouse_id;
	}

	/**
	 * Rollup payload format version.
	 */
	public function rollup_version(): int {
		return $this->rollup_version;
	}

	/**
	 * Normalized counters.
	 *
	 * @return array<string, mixed>
	 */
	public function counters(): array {
		return $this->counters;
	}

	/**
	 * Duration stats keyed by hop id.
	 *
	 * @return array<string, array{count: int, sum: float, avg: float|null, p50: float|null, p90: float|null}>
	 */
	public function durations(): array {
		return $this->durations;
	}

	/**
	 * Highest event id observed while computing this snapshot.
	 */
	public function source_event_max_id(): ?int {
		return $this->source_event_max_id;
	}

	/**
	 * Array form for APIs and persistence helpers.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'utc_date'            => $this->utc_date,
			'warehouse_id'        => $this->warehouse_id,
			'rollup_version'      => $this->rollup_version,
			'counters'            => $this->counters,
			'durations'           => $this->durations,
			'wave_derived'        => CounterCalculator::wave_derived( $this->counters['waves'] ),
			'source_event_max_id' => $this->source_event_max_id,
		);
	}
}
