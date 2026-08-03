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
use MPCF\Domain\EventTimelinePage;

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
	 * The full chain for one fulfillment, oldest first. Still genuinely
	 * needed unbounded by hash-chaining and by the tests that assert
	 * full-chain behavior directly — {@see timeline_page_for_fulfillment()}
	 * and {@see recent_for_fulfillment()} are what the Detail and Workspace
	 * screens actually read (Architecture Plan §IV.10, risk M2-R11: an
	 * admin screen fetching every event just to display a handful was
	 * exactly the "currently-unbounded `timeline_for_fulfillment()`" this
	 * milestone was scoped to fix).
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @return list<array<string, mixed>>
	 */
	public function timeline_for_fulfillment( int $fulfillment_id ): array;

	/**
	 * One page of a fulfillment's chain, oldest first overall — the
	 * Fulfillment Detail screen's paginated audit trail.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @param int $page           1-indexed page number.
	 * @param int $per_page       Rows per page.
	 */
	public function timeline_page_for_fulfillment( int $fulfillment_id, int $page, int $per_page ): EventTimelinePage;

	/**
	 * The `$limit` most recently appended events for a fulfillment, oldest
	 * first among themselves — the Packing Workspace's "last five events"
	 * (Architecture Plan §IV.5.2), which never paginates further within the
	 * workspace (a link to the Detail screen's full, paginated trail covers
	 * that).
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @param int $limit          Maximum rows to return.
	 * @return list<array<string, mixed>>
	 */
	public function recent_for_fulfillment( int $fulfillment_id, int $limit ): array;

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
