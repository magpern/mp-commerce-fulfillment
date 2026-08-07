<?php
/**
 * Aggregated doctor report.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Diagnostics;

/**
 * Summary + results for CLI / Site Health consumers.
 */
final class DoctorReport {

	/**
	 * Builds a report from checker results.
	 *
	 * @param array $results All check results.
	 */
	public function __construct(
		private array $results
	) {
	}

	/**
	 * Returns all check results.
	 *
	 * @return list<CheckResult>
	 */
	public function results(): array {
		return $this->results;
	}

	/**
	 * Whether any check failed.
	 */
	public function has_failures(): bool {
		foreach ( $this->results as $result ) {
			if ( CheckStatus::FAIL === $result->status() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether any check warned.
	 */
	public function has_warnings(): bool {
		foreach ( $this->results as $result ) {
			if ( CheckStatus::WARN === $result->status() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Number of passing checks.
	 */
	public function pass_count(): int {
		return $this->count_status( CheckStatus::PASS );
	}

	/**
	 * Number of warning checks.
	 */
	public function warn_count(): int {
		return $this->count_status( CheckStatus::WARN );
	}

	/**
	 * Number of failing checks.
	 */
	public function fail_count(): int {
		return $this->count_status( CheckStatus::FAIL );
	}

	/**
	 * Serializes the report for JSON output.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'pass'    => $this->pass_count(),
			'warn'    => $this->warn_count(),
			'fail'    => $this->fail_count(),
			'results' => array_map(
				static fn( CheckResult $r ) => $r->to_array(),
				$this->results
			),
		);
	}

	/**
	 * Counts results with a given status.
	 *
	 * @param string $status CheckStatus::* value.
	 */
	private function count_status( string $status ): int {
		$n = 0;
		foreach ( $this->results as $result ) {
			if ( $status === $result->status() ) {
				++$n;
			}
		}

		return $n;
	}
}
