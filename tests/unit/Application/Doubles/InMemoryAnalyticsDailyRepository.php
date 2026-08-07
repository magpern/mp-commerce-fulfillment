<?php
/**
 * In-memory analytics daily repository for unit tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Doubles;

use MPCF\Domain\Repository\AnalyticsDailyRepository;
use MPCF\Engine\Analytics\DailyMetrics;

/**
 * Array-backed rollup store.
 */
final class InMemoryAnalyticsDailyRepository implements AnalyticsDailyRepository {

	/**
	 * @var array<string, DailyMetrics>
	 */
	private array $rows = array();

	public function find( string $utc_date, int $warehouse_id ): ?DailyMetrics {
		$key = $utc_date . ':' . $warehouse_id;
		return $this->rows[ $key ] ?? null;
	}

	/**
	 * @return list<DailyMetrics>
	 */
	public function find_range( string $from_utc_date, string $to_utc_date_inclusive, int $warehouse_id ): array {
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( $row->warehouse_id() !== $warehouse_id ) {
				continue;
			}
			if ( $row->utc_date() >= $from_utc_date && $row->utc_date() <= $to_utc_date_inclusive ) {
				$out[] = $row;
			}
		}
		usort(
			$out,
			static fn( DailyMetrics $a, DailyMetrics $b ): int => strcmp( $a->utc_date(), $b->utc_date() )
		);
		return $out;
	}

	public function upsert( DailyMetrics $metrics, string $computed_at_utc ): void {
		unset( $computed_at_utc );
		$key                = $metrics->utc_date() . ':' . $metrics->warehouse_id();
		$this->rows[ $key ] = $metrics;
	}

	public function has_current_version( string $utc_date, int $warehouse_id, int $current_version ): bool {
		$row = $this->find( $utc_date, $warehouse_id );
		return null !== $row && $row->rollup_version() === $current_version;
	}

	public function count_obsolete( int $version ): int {
		$n = 0;
		foreach ( $this->rows as $row ) {
			if ( $row->rollup_version() < $version ) {
				++$n;
			}
		}
		return $n;
	}
}
