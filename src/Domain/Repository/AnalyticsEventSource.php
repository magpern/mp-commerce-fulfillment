<?php
/**
 * Port: read operational data for analytics calculators.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Repository;

use DateTimeImmutable;

/**
 * Implemented in Infrastructure. No workflow mutations.
 */
interface AnalyticsEventSource {

	/**
	 * Raw counter tallies for [from, to) UTC window and warehouse.
	 *
	 * @param DateTimeImmutable $from         Inclusive window start.
	 * @param DateTimeImmutable $to           Exclusive window end.
	 * @param int               $warehouse_id Warehouse scope.
	 * @return array<string, mixed>
	 */
	public function counter_raw( DateTimeImmutable $from, DateTimeImmutable $to, int $warehouse_id ): array;

	/**
	 * Hop duration samples in seconds for [from, to), keyed by hop id.
	 *
	 * @param DateTimeImmutable $from         Inclusive window start.
	 * @param DateTimeImmutable $to           Exclusive window end.
	 * @param int               $warehouse_id Warehouse scope.
	 * @return array<string, list<float>>
	 */
	public function duration_samples( DateTimeImmutable $from, DateTimeImmutable $to, int $warehouse_id ): array;

	/**
	 * Ages (seconds in current state) for open fulfillments.
	 *
	 * @param array             $open_states  Open workflow state keys.
	 * @param int               $warehouse_id Warehouse scope.
	 * @param DateTimeImmutable $now          Reference "now".
	 * @return list<int>
	 */
	public function open_queue_ages_seconds( array $open_states, int $warehouse_id, DateTimeImmutable $now ): array;

	/**
	 * Count fulfillments currently in the given states.
	 *
	 * @param array $exception_states State keys to count.
	 * @param int   $warehouse_id     Warehouse scope.
	 */
	public function count_in_states( array $exception_states, int $warehouse_id ): int;

	/**
	 * Max event id observed in the window (for rebuild auditing).
	 *
	 * @param DateTimeImmutable $to Exclusive window end.
	 */
	public function max_event_id_through( DateTimeImmutable $to ): ?int;
}
