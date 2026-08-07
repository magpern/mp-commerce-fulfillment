<?php
/**
 * CSV export from AnalyticsService DTOs (UTF-8, deterministic columns).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Analytics;

/**
 * No Excel/XLSX. RFC-compatible escaping. Same report DTO as UI.
 */
final class AnalyticsCsvExporter {

	public const TYPE_THROUGHPUT = 'throughput';

	public const TYPE_DURATIONS = 'durations';

	public const TYPE_WAVES = 'waves';

	public const TYPE_NOTIFICATIONS = 'notifications';

	public const TYPE_EXCEPTIONS = 'exceptions';

	/**
	 * Exports a report DTO as CSV for `$type`.
	 *
	 * @param array<string, mixed> $report_dto From {@see AnalyticsService::report_dto()}.
	 * @param string               $type       Export type constant.
	 */
	public function export( array $report_dto, string $type ): string {
		$days = $report_dto['days'] ?? array();
		if ( ! is_array( $days ) ) {
			$days = array();
		}

		switch ( $type ) {
			case self::TYPE_DURATIONS:
				return $this->durations_csv( $days );
			case self::TYPE_WAVES:
				return $this->waves_csv( $days );
			case self::TYPE_NOTIFICATIONS:
				return $this->notifications_csv( $days );
			case self::TYPE_EXCEPTIONS:
				return $this->exceptions_csv( $days );
			case self::TYPE_THROUGHPUT:
			default:
				return $this->throughput_csv( $days );
		}
	}

	/**
	 * Throughput columns per day.
	 *
	 * @param list<array<string, mixed>> $days Report day rows.
	 */
	private function throughput_csv( array $days ): string {
		$headers = array( 'utc_date', 'source', 'created', 'packed', 'shipped', 'scans_total', 'docs_rendered', 'photos_captured' );
		$rows    = array( $headers );
		foreach ( $days as $day ) {
			$c      = $day['counters'] ?? array();
			$rows[] = array(
				(string) ( $day['utc_date'] ?? '' ),
				(string) ( $day['source'] ?? '' ),
				(string) ( $c['fulfillments']['created'] ?? 0 ),
				(string) ( $c['fulfillments']['packed'] ?? 0 ),
				(string) ( $c['fulfillments']['shipped'] ?? 0 ),
				(string) ( $c['scans']['total'] ?? 0 ),
				(string) ( $c['documents']['rendered'] ?? 0 ),
				(string) ( $c['photos']['captured'] ?? 0 ),
			);
		}

		return $this->encode( $rows );
	}

	/**
	 * Duration hop columns per day.
	 *
	 * @param list<array<string, mixed>> $days Report day rows.
	 */
	private function durations_csv( array $days ): string {
		$headers = array( 'utc_date', 'hop', 'count', 'avg', 'p50', 'p90' );
		$rows    = array( $headers );
		foreach ( $days as $day ) {
			$durs = $day['durations'] ?? array();
			if ( ! is_array( $durs ) ) {
				continue;
			}
			foreach ( $durs as $hop => $stats ) {
				if ( ! is_array( $stats ) ) {
					continue;
				}
				$rows[] = array(
					(string) ( $day['utc_date'] ?? '' ),
					(string) $hop,
					(string) ( $stats['count'] ?? 0 ),
					$this->num( $stats['avg'] ?? null ),
					$this->num( $stats['p50'] ?? null ),
					$this->num( $stats['p90'] ?? null ),
				);
			}
		}

		return $this->encode( $rows );
	}

	/**
	 * Wave summary columns per day.
	 *
	 * @param list<array<string, mixed>> $days Report day rows.
	 */
	private function waves_csv( array $days ): string {
		$headers = array( 'utc_date', 'completed', 'abandoned', 'avg_members', 'avg_items', 'avg_duration_seconds', 'abandoned_rate' );
		$rows    = array( $headers );
		foreach ( $days as $day ) {
			$w      = $day['counters']['waves'] ?? array();
			$d      = $day['wave_derived'] ?? array();
			$rows[] = array(
				(string) ( $day['utc_date'] ?? '' ),
				(string) ( $w['completed'] ?? 0 ),
				(string) ( $w['abandoned'] ?? 0 ),
				$this->num( $d['avg_members'] ?? null ),
				$this->num( $d['avg_items'] ?? null ),
				$this->num( $d['avg_duration_seconds'] ?? null ),
				$this->num( $d['abandoned_rate'] ?? null ),
			);
		}

		return $this->encode( $rows );
	}

	/**
	 * Notification columns per day.
	 *
	 * @param list<array<string, mixed>> $days Report day rows.
	 */
	private function notifications_csv( array $days ): string {
		$headers = array( 'utc_date', 'sent', 'failed', 'suppressed' );
		$rows    = array( $headers );
		foreach ( $days as $day ) {
			$n      = $day['counters']['notifications'] ?? array();
			$rows[] = array(
				(string) ( $day['utc_date'] ?? '' ),
				(string) ( $n['sent'] ?? 0 ),
				(string) ( $n['failed'] ?? 0 ),
				(string) ( $n['suppressed'] ?? 0 ),
			);
		}

		return $this->encode( $rows );
	}

	/**
	 * Top-reason exception columns per day.
	 *
	 * @param list<array<string, mixed>> $days Report day rows.
	 */
	private function exceptions_csv( array $days ): string {
		$headers = array( 'utc_date', 'family', 'reason', 'count' );
		$rows    = array( $headers );
		foreach ( $days as $day ) {
			$tops = $day['counters']['top_reasons'] ?? array();
			if ( ! is_array( $tops ) ) {
				continue;
			}
			foreach ( $tops as $family => $list ) {
				if ( ! is_array( $list ) ) {
					continue;
				}
				foreach ( $list as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$rows[] = array(
						(string) ( $day['utc_date'] ?? '' ),
						(string) $family,
						(string) ( $row['reason'] ?? '' ),
						(string) ( $row['count'] ?? 0 ),
					);
				}
			}
		}

		return $this->encode( $rows );
	}

	/**
	 * Joins rows into an RFC-style CSV string.
	 *
	 * @param array $rows Header plus data rows.
	 */
	private function encode( array $rows ): string {
		$lines = array();
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $row as $cell ) {
				$cells[] = $this->escape( (string) $cell );
			}
			$lines[] = implode( ',', $cells );
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Escapes a single CSV cell.
	 *
	 * @param string $value Raw cell value.
	 */
	private function escape( string $value ): string {
		if ( str_contains( $value, ',' ) || str_contains( $value, '"' ) || str_contains( $value, "\n" ) || str_contains( $value, "\r" ) ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}

		return $value;
	}

	/**
	 * Formats a numeric cell (empty when null/non-numeric).
	 *
	 * @param mixed $v Value to format.
	 */
	private function num( $v ): string {
		if ( null === $v ) {
			return '';
		}

		return is_float( $v ) || is_int( $v ) ? (string) $v : '';
	}
}
