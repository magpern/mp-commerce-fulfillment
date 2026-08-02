<?php
/**
 * Persistence contract for the append-only, hash-chained audit log.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Repository;

use DateTimeImmutable;
use MPCF\Domain\Event\DomainEvent;

/**
 * Implemented in Infrastructure ({@see \MPCF\Infrastructure\Database\WpdbEventRepository}),
 * confined there per invariant I7. This interface exposes exactly
 * `append()` and readers, mirroring invariant I5 (append-only) at the
 * contract level, not only in the concrete class.
 */
interface EventRepository {

	/**
	 * Appends one event to the chain, computing its hash from `$prev_hash`
	 * and the event's canonical payload. Returns the new event's hash, so
	 * the caller can chain a second event in the same request without a
	 * round trip back through {@see last_hash_for_fulfillment()}.
	 *
	 * @param DomainEvent $event     Event to append.
	 * @param string|null $prev_hash Previous event's hash for this fulfillment, or null for its first event.
	 */
	public function append( DomainEvent $event, ?string $prev_hash ): string;

	/**
	 * The hash of the most recently appended event for a fulfillment, or
	 * null if it has none yet.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function last_hash_for_fulfillment( int $fulfillment_id ): ?string;

	/**
	 * The full chain for one fulfillment, oldest first — the Fulfillment
	 * Detail timeline's data source.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @return list<array<string, mixed>>
	 */
	public function timeline_for_fulfillment( int $fulfillment_id ): array;

	/**
	 * How many `fulfillment.state_changed` events since `$since` moved a
	 * fulfillment into `$state` — the Dashboard's "packed today"/"shipped
	 * today" stats' data source (a fulfillment currently further along than
	 * `$state` still counts, since it passed through it earlier today).
	 *
	 * @param string            $state State a fulfillment entered.
	 * @param DateTimeImmutable $since Only count events at or after this moment.
	 */
	public function count_state_entries_since( string $state, DateTimeImmutable $since ): int;
}
