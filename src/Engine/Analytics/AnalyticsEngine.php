<?php
/**
 * AnalyticsEngine — LIVE / ROLLUP / REBUILD orchestrator.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\Analytics;

use DateTimeImmutable;
use InvalidArgumentException;
use MPCF\Domain\Repository\AnalyticsDailyRepository;
use MPCF\Domain\Repository\AnalyticsEventSource;

/**
 * Sole orchestrator for analytics calculation modes. Enforces historical
 * immutability during normal operation.
 */
final class AnalyticsEngine {

	/**
	 * Default warehouse when the site is single-warehouse.
	 */
	public const DEFAULT_WAREHOUSE_ID = 1;

	/**
	 * Operational event / queue source.
	 *
	 * @var AnalyticsEventSource
	 */
	private AnalyticsEventSource $source;

	/**
	 * Materialized daily rollup store.
	 *
	 * @var AnalyticsDailyRepository
	 */
	private AnalyticsDailyRepository $daily;

	/**
	 * Builds the engine.
	 *
	 * @param AnalyticsEventSource     $source Operational event / queue source.
	 * @param AnalyticsDailyRepository $daily  Materialized daily rollup store.
	 */
	public function __construct( AnalyticsEventSource $source, AnalyticsDailyRepository $daily ) {
		$this->source = $source;
		$this->daily  = $daily;
	}

	/**
	 * LIVE: compute from source for a window. Never writes rollups.
	 *
	 * @param DateTimeImmutable $from             Inclusive window start (UTC).
	 * @param DateTimeImmutable $to               Exclusive window end (UTC).
	 * @param DateTimeImmutable $now              Reference "now" for ageing.
	 * @param int               $warehouse_id     Warehouse scope.
	 * @param array             $open_states      Open workflow state keys.
	 * @param array             $exception_states Exception workflow state keys.
	 * @return array{metrics: DailyMetrics, ageing: array<string, mixed>}
	 */
	public function live(
		DateTimeImmutable $from,
		DateTimeImmutable $to,
		DateTimeImmutable $now,
		int $warehouse_id,
		array $open_states,
		array $exception_states
	): array {
		$metrics = $this->compute_window( $from, $to, $warehouse_id, AnalyticsMode::LIVE );
		$ageing  = QueueAgeingCalculator::summarize(
			$this->source->open_queue_ages_seconds( $open_states, $warehouse_id, $now ),
			$this->source->count_in_states( $exception_states, $warehouse_id )
		);

		return array(
			'metrics' => $metrics,
			'ageing'  => $ageing,
		);
	}

	/**
	 * ROLLUP: materialize a closed UTC day if missing or obsolete version.
	 * Does not rewrite a valid current-version row.
	 *
	 * @param DateTimeImmutable $day          Any instant on the UTC day to roll up.
	 * @param int               $warehouse_id Warehouse scope.
	 * @param DateTimeImmutable $now          Reference "now" (must not be that day).
	 * @return array{status: string, metrics: ?DailyMetrics}
	 * @throws InvalidArgumentException When `$day` is the current UTC day.
	 */
	public function rollup_day( DateTimeImmutable $day, int $warehouse_id, DateTimeImmutable $now ): array {
		$utc_date = UtcDay::key( $day );
		if ( UtcDay::is_today( $day, $now ) ) {
			throw new InvalidArgumentException( 'ROLLUP refuses the current UTC day; use LIVE.' );
		}

		if ( $this->daily->has_current_version( $utc_date, $warehouse_id, RollupVersion::CURRENT ) ) {
			return array(
				'status'  => 'unchanged',
				'metrics' => $this->daily->find( $utc_date, $warehouse_id ),
			);
		}

		$from    = UtcDay::start( $day );
		$to      = UtcDay::end_exclusive( $day );
		$metrics = $this->compute_window( $from, $to, $warehouse_id, AnalyticsMode::ROLLUP );
		$this->daily->upsert( $metrics, $now->setTimezone( UtcDay::timezone() )->format( 'Y-m-d H:i:s' ) );

		return array(
			'status'  => 'written',
			'metrics' => $metrics,
		);
	}

	/**
	 * REBUILD: always recompute and rewrite historical day.
	 *
	 * @param DateTimeImmutable $day          Any instant on the UTC day to rebuild.
	 * @param int               $warehouse_id Warehouse scope.
	 * @param DateTimeImmutable $now          Reference "now" (must not be that day).
	 * @return DailyMetrics
	 * @throws InvalidArgumentException When `$day` is the current UTC day.
	 */
	public function rebuild_day( DateTimeImmutable $day, int $warehouse_id, DateTimeImmutable $now ): DailyMetrics {
		$utc_date = UtcDay::key( $day );
		if ( UtcDay::is_today( $day, $now ) ) {
			throw new InvalidArgumentException( 'REBUILD refuses the current UTC day; use LIVE.' );
		}

		$from    = UtcDay::start( $day );
		$to      = UtcDay::end_exclusive( $day );
		$metrics = $this->compute_window( $from, $to, $warehouse_id, AnalyticsMode::REBUILD );
		$this->daily->upsert( $metrics, $now->setTimezone( UtcDay::timezone() )->format( 'Y-m-d H:i:s' ) );

		return $metrics;
	}

	/**
	 * Read historical day: prefer rollup; do not recompute/write on miss
	 * during normal reads (caller may trigger ROLLUP separately).
	 *
	 * @param string $utc_date     UTC day key.
	 * @param int    $warehouse_id Warehouse scope.
	 */
	public function read_historical( string $utc_date, int $warehouse_id ): ?DailyMetrics {
		return $this->daily->find( $utc_date, $warehouse_id );
	}

	/**
	 * Reads materialized rollups for an inclusive UTC day range.
	 *
	 * @param string $from_utc           Inclusive start day key.
	 * @param string $to_utc_inclusive   Inclusive end day key.
	 * @param int    $warehouse_id       Warehouse scope.
	 * @return list<DailyMetrics>
	 */
	public function read_historical_range( string $from_utc, string $to_utc_inclusive, int $warehouse_id ): array {
		return $this->daily->find_range( $from_utc, $to_utc_inclusive, $warehouse_id );
	}

	/**
	 * Count rollup rows below the current format version.
	 */
	public function count_obsolete_rows(): int {
		return $this->daily->count_obsolete( RollupVersion::CURRENT );
	}

	/**
	 * Computes counters + durations for a half-open UTC window.
	 *
	 * @param DateTimeImmutable $from         Inclusive window start.
	 * @param DateTimeImmutable $to           Exclusive window end.
	 * @param int               $warehouse_id Warehouse scope.
	 * @param string            $mode         LIVE, ROLLUP, or REBUILD.
	 * @throws InvalidArgumentException When `$mode` is unknown.
	 */
	private function compute_window(
		DateTimeImmutable $from,
		DateTimeImmutable $to,
		int $warehouse_id,
		string $mode
	): DailyMetrics {
		if ( ! AnalyticsMode::is_valid( $mode ) ) {
			throw new InvalidArgumentException( 'Unknown analytics mode.' );
		}

		$raw       = $this->source->counter_raw( $from, $to, $warehouse_id );
		$samples   = $this->source->duration_samples( $from, $to, $warehouse_id );
		$durations = DurationCalculator::summarize_hops( $samples );
		$max_id    = $this->source->max_event_id_through( $to );

		return new DailyMetrics(
			UtcDay::key( $from ),
			$warehouse_id,
			RollupVersion::CURRENT,
			$raw,
			$durations,
			$max_id
		);
	}
}
