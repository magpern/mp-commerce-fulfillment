<?php
/**
 * Application façade for Operational Analytics (Part XI).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Analytics;

use DateTimeImmutable;
use MPCF\Domain\Clock;
use MPCF\Domain\Repository\AnalyticsDiagnosticsSource;
use MPCF\Domain\Workflow\WorkflowDefinition;
use MPCF\Engine\Analytics\AnalyticsEngine;
use MPCF\Engine\Analytics\CounterCalculator;
use MPCF\Engine\Analytics\DailyMetrics;
use MPCF\Engine\Analytics\UtcDay;

/**
 * WP-free façade: permissions and HTTP stay in Admin/REST. CSV DTOs are
 * produced here so exporters cannot drift from UI payloads.
 */
final class AnalyticsService {

	/**
	 * LIVE / ROLLUP / REBUILD orchestrator.
	 *
	 * @var AnalyticsEngine
	 */
	private AnalyticsEngine $engine;

	/**
	 * Source of "now".
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Governing workflow (open / exception states).
	 *
	 * @var WorkflowDefinition
	 */
	private WorkflowDefinition $workflow;

	/**
	 * Bounded diagnostic list reader.
	 *
	 * @var AnalyticsDiagnosticsSource
	 */
	private AnalyticsDiagnosticsSource $diagnostics;

	/**
	 * Warehouse scope for this façade.
	 *
	 * @var int
	 */
	private int $warehouse_id;

	/**
	 * Builds the service.
	 *
	 * @param AnalyticsEngine            $engine       LIVE / ROLLUP / REBUILD orchestrator.
	 * @param Clock                      $clock        Source of "now".
	 * @param WorkflowDefinition         $workflow     Governing workflow.
	 * @param AnalyticsDiagnosticsSource $diagnostics  Bounded diagnostic list reader.
	 * @param int                        $warehouse_id Warehouse scope.
	 */
	public function __construct(
		AnalyticsEngine $engine,
		Clock $clock,
		WorkflowDefinition $workflow,
		AnalyticsDiagnosticsSource $diagnostics,
		int $warehouse_id = AnalyticsEngine::DEFAULT_WAREHOUSE_ID
	) {
		$this->engine       = $engine;
		$this->clock        = $clock;
		$this->workflow     = $workflow;
		$this->diagnostics  = $diagnostics;
		$this->warehouse_id = $warehouse_id;
	}

	/**
	 * Overview: LIVE today + optional rollup summary for recent closed days.
	 *
	 * @return array<string, mixed>
	 */
	public function overview(): array {
		$now   = $this->clock->now();
		$today = AnalyticsRange::today( $now );
		$live  = $this->engine->live(
			$today->from(),
			$today->to_exclusive(),
			$now,
			$this->warehouse_id,
			$this->open_states(),
			$this->exception_states()
		);

		$yesterday = UtcDay::yesterday_start( $now );
		$hist      = $this->engine->read_historical( UtcDay::key( $yesterday ), $this->warehouse_id );

		return array(
			'mode'         => 'live',
			'utc_today'    => UtcDay::key( $now ),
			'warehouse_id' => $this->warehouse_id,
			'today'        => $live['metrics']->to_array(),
			'ageing'       => $live['ageing'],
			'yesterday'    => null === $hist ? null : $hist->to_array(),
			'cards'        => $this->overview_cards( $live['metrics'], $live['ageing'] ),
			'timeline'     => $live['metrics']->durations(),
		);
	}

	/**
	 * Stage Timeline for today (LIVE) or a closed day (rollup read).
	 *
	 * @param string|null $utc_date Optional closed day key; null means today.
	 * @return array<string, mixed>
	 */
	public function timeline( ?string $utc_date = null ): array {
		$now = $this->clock->now();
		if ( null === $utc_date || UtcDay::key( $now ) === $utc_date ) {
			$ov = $this->overview();

			return array(
				'utc_date'  => $ov['utc_today'],
				'source'    => 'live',
				'durations' => $ov['timeline'],
			);
		}

		$row = $this->engine->read_historical( $utc_date, $this->warehouse_id );

		return array(
			'utc_date'  => $utc_date,
			'source'    => null === $row ? 'missing' : 'rollup',
			'durations' => null === $row ? array() : $row->durations(),
		);
	}

	/**
	 * LIVE queue-ageing snapshot for today.
	 *
	 * @return array<string, mixed>
	 */
	public function queue_ageing(): array {
		$ov = $this->overview();

		return array(
			'utc_today' => $ov['utc_today'],
			'ageing'    => $ov['ageing'],
		);
	}

	/**
	 * LIVE wave counters for today.
	 *
	 * @return array<string, mixed>
	 */
	public function waves_today(): array {
		$ov       = $this->overview();
		$counters = $ov['today']['counters']['waves'];

		return array(
			'utc_today' => $ov['utc_today'],
			'waves'     => $counters,
			'derived'   => CounterCalculator::wave_derived( $counters ),
		);
	}

	/**
	 * Report DTO for a range: LIVE for today slice + rollups for closed days.
	 *
	 * @param AnalyticsRange $range Requested UTC range.
	 * @return array<string, mixed>
	 */
	public function report( AnalyticsRange $range ): array {
		$now       = $this->clock->now();
		$days      = array();
		$cursor    = UtcDay::start( $range->from() );
		$end       = $range->to_exclusive();
		$today_key = UtcDay::key( $now );

		while ( $cursor < $end ) {
			$key = UtcDay::key( $cursor );
			if ( $key === $today_key ) {
				$live   = $this->engine->live(
					UtcDay::start( $cursor ),
					UtcDay::end_exclusive( $cursor ),
					$now,
					$this->warehouse_id,
					$this->open_states(),
					$this->exception_states()
				);
				$days[] = array_merge( $live['metrics']->to_array(), array( 'source' => 'live' ) );
			} else {
				$row = $this->engine->read_historical( $key, $this->warehouse_id );
				if ( null !== $row ) {
					$days[] = array_merge( $row->to_array(), array( 'source' => 'rollup' ) );
				} else {
					$days[] = array(
						'utc_date'     => $key,
						'warehouse_id' => $this->warehouse_id,
						'source'       => 'missing',
						'counters'     => CounterCalculator::empty(),
						'durations'    => array(),
					);
				}
			}
			$cursor = $cursor->modify( '+1 day' );
		}

		return array(
			'preset'       => $range->preset(),
			'from_utc'     => UtcDay::key( $range->from() ),
			'to_utc'       => UtcDay::key( $end->modify( '-1 second' ) ),
			'warehouse_id' => $this->warehouse_id,
			'days'         => $days,
		);
	}

	/**
	 * Same DTO as {@see report()} — CSV must use this.
	 *
	 * @param AnalyticsRange $range Requested UTC range.
	 * @return array<string, mixed>
	 */
	public function report_dto( AnalyticsRange $range ): array {
		return $this->report( $range );
	}

	/**
	 * Bounded operational diagnostics (read-only).
	 *
	 * @param int $limit Max rows per diagnostic list.
	 * @return array<string, mixed>
	 */
	public function diagnostics( int $limit = 25 ): array {
		$now    = $this->clock->now();
		$live   = $this->engine->live(
			UtcDay::start( $now ),
			UtcDay::end_exclusive( $now ),
			$now,
			$this->warehouse_id,
			$this->open_states(),
			$this->exception_states()
		);
		$ageing = $live['ageing'];
		$top    = $live['metrics']->counters()['top_reasons'];

		return array(
			'utc_today'          => UtcDay::key( $now ),
			'queue_ageing'       => $ageing,
			'top_rejection'      => $top['rejection'],
			'top_guard'          => $top['guard'],
			'top_scan'           => $top['scan'],
			'top_notification'   => $top['notification'],
			'notification_rates' => $this->notification_rates( $live['metrics'] ),
			'slow_fulfillments'  => $this->diagnostics->slow_fulfillments( $this->open_states(), $this->warehouse_id, $now, $limit ),
			'stalled_waves'      => $this->diagnostics->stalled_waves( $this->warehouse_id, $now, $limit ),
			'limit'              => $limit,
		);
	}

	/**
	 * ROLLUP closed days from `$from_ymd` through yesterday (inclusive).
	 *
	 * @param string      $from_ymd          Inclusive start day key.
	 * @param string|null $to_ymd_inclusive  Inclusive end day key (default: yesterday).
	 * @return array{written: int, unchanged: int, skipped_today: int}
	 */
	public function rollup_range( string $from_ymd, ?string $to_ymd_inclusive = null ): array {
		$now  = $this->clock->now();
		$from = UtcDay::from_key( $from_ymd );
		$to   = null === $to_ymd_inclusive
			? UtcDay::yesterday_start( $now )
			: UtcDay::from_key( $to_ymd_inclusive );

		$written   = 0;
		$unchanged = 0;
		$skipped   = 0;
		$cursor    = $from;
		while ( $cursor <= $to ) {
			if ( UtcDay::is_today( $cursor, $now ) ) {
				++$skipped;
				$cursor = $cursor->modify( '+1 day' );
				continue;
			}
			$result = $this->engine->rollup_day( $cursor, $this->warehouse_id, $now );
			if ( 'written' === $result['status'] ) {
				++$written;
			} else {
				++$unchanged;
			}
			$cursor = $cursor->modify( '+1 day' );
		}

		return array(
			'written'       => $written,
			'unchanged'     => $unchanged,
			'skipped_today' => $skipped,
		);
	}

	/**
	 * REBUILD closed days (always rewrite).
	 *
	 * @param string $from_ymd         Inclusive start day key.
	 * @param string $to_ymd_inclusive Inclusive end day key.
	 * @return array{rebuilt: int, obsolete_remaining: int}
	 */
	public function rebuild_range( string $from_ymd, string $to_ymd_inclusive ): array {
		$now     = $this->clock->now();
		$from    = UtcDay::from_key( $from_ymd );
		$to      = UtcDay::from_key( $to_ymd_inclusive );
		$rebuilt = 0;
		$cursor  = $from;
		while ( $cursor <= $to ) {
			if ( UtcDay::is_today( $cursor, $now ) ) {
				$cursor = $cursor->modify( '+1 day' );
				continue;
			}
			$this->engine->rebuild_day( $cursor, $this->warehouse_id, $now );
			++$rebuilt;
			$cursor = $cursor->modify( '+1 day' );
		}

		return array(
			'rebuilt'            => $rebuilt,
			'obsolete_remaining' => $this->engine->count_obsolete_rows(),
		);
	}

	/**
	 * Count rollup rows below the current format version.
	 */
	public function count_obsolete(): int {
		return $this->engine->count_obsolete_rows();
	}

	/**
	 * Overview KPI cards for the LIVE day.
	 *
	 * @param DailyMetrics         $today  LIVE metrics for today.
	 * @param array<string, mixed> $ageing Queue-ageing summary.
	 * @return array<string, int|float|null>
	 */
	private function overview_cards( DailyMetrics $today, array $ageing ): array {
		$c    = $today->counters();
		$n    = $c['notifications'];
		$sent = (int) $n['sent'];
		$fail = (int) $n['failed'];
		$den  = $sent + $fail;

		return array(
			'created_today'             => (int) $c['fulfillments']['created'],
			'packed_today'              => (int) $c['fulfillments']['packed'],
			'shipped_today'             => (int) $c['fulfillments']['shipped'],
			'open_queue'                => (int) $ageing['open_count'],
			'exceptions'                => (int) $ageing['exception_count'],
			'waves_completed'           => (int) $c['waves']['completed'],
			'notification_failure_rate' => $den > 0 ? ( $fail / $den ) : null,
		);
	}

	/**
	 * Notification outcome rates for diagnostics.
	 *
	 * @param DailyMetrics $m Metrics snapshot.
	 * @return array<string, float|null>
	 */
	private function notification_rates( DailyMetrics $m ): array {
		$n     = $m->counters()['notifications'];
		$total = (int) $n['sent'] + (int) $n['failed'] + (int) $n['suppressed'];

		return array(
			'sent'       => $total > 0 ? ( (int) $n['sent'] / $total ) : null,
			'failed'     => $total > 0 ? ( (int) $n['failed'] / $total ) : null,
			'suppressed' => $total > 0 ? ( (int) $n['suppressed'] / $total ) : null,
		);
	}

	/**
	 * Non-terminal workflow state keys.
	 *
	 * @return list<string>
	 */
	private function open_states(): array {
		$out = array();
		foreach ( $this->workflow->states() as $state ) {
			if ( ! $state->is_terminal() ) {
				$out[] = $state->key();
			}
		}

		return $out;
	}

	/**
	 * Exception workflow state keys.
	 *
	 * @return list<string>
	 */
	private function exception_states(): array {
		$out = array();
		foreach ( $this->workflow->states() as $state ) {
			if ( $state->is_exception() ) {
				$out[] = $state->key();
			}
		}

		return $out;
	}
}
