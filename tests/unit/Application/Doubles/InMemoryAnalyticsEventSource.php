<?php
/**
 * In-memory analytics event source for unit tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Doubles;

use DateTimeImmutable;
use MPCF\Domain\Repository\AnalyticsEventSource;
use MPCF\Engine\Analytics\CounterCalculator;

/**
 * Configurable stub.
 */
final class InMemoryAnalyticsEventSource implements AnalyticsEventSource {

	/**
	 * @var array<string, mixed>
	 */
	public array $counters = array();

	/**
	 * @var array<string, list<float>>
	 */
	public array $durations = array();

	/**
	 * Open-queue ages in seconds.
	 *
	 * @var list<int>
	 */
	public array $ages = array();

	/**
	 * Exception-state count returned by count_in_states().
	 *
	 * @var int
	 */
	public int $exception_count = 0;

	/**
	 * Max event id returned by max_event_id_through().
	 *
	 * @var int|null
	 */
	public ?int $max_event_id = 10;

	/**
	 * @return array<string, mixed>
	 */
	public function counter_raw( DateTimeImmutable $from, DateTimeImmutable $to, int $warehouse_id ): array {
		unset( $from, $to, $warehouse_id );
		return array() === $this->counters ? CounterCalculator::empty() : $this->counters;
	}

	/**
	 * @return array<string, list<float>>
	 */
	public function duration_samples( DateTimeImmutable $from, DateTimeImmutable $to, int $warehouse_id ): array {
		unset( $from, $to, $warehouse_id );
		return $this->durations;
	}

	/**
	 * Ages (seconds in current state) for open fulfillments.
	 *
	 * @param array             $open_states  Open workflow state keys.
	 * @param int               $warehouse_id Warehouse scope.
	 * @param DateTimeImmutable $now          Reference "now".
	 * @return list<int>
	 */
	public function open_queue_ages_seconds( array $open_states, int $warehouse_id, DateTimeImmutable $now ): array {
		unset( $open_states, $warehouse_id, $now );
		return $this->ages;
	}

	/**
	 * Count fulfillments currently in the given states.
	 *
	 * @param array $exception_states State keys to count.
	 * @param int   $warehouse_id     Warehouse scope.
	 */
	public function count_in_states( array $exception_states, int $warehouse_id ): int {
		unset( $exception_states, $warehouse_id );
		return $this->exception_count;
	}

	public function max_event_id_through( DateTimeImmutable $to ): ?int {
		unset( $to );
		return $this->max_event_id;
	}
}
