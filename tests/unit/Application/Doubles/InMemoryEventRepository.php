<?php
/**
 * In-memory test double for the append-only audit event repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Doubles;

use DateTimeImmutable;
use MPCF\Domain\Event\Canonicalizer;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\EventTimelinePage;
use MPCF\Domain\Repository\EventRepository;

/**
 * In-memory audit event store; no real database involved.
 */
final class InMemoryEventRepository implements EventRepository {

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = array();

	public function append( DomainEvent $event, ?string $prev_hash ): string {
		$hash = Canonicalizer::hash( $prev_hash, $event->hashable_payload() );

		$this->rows[] = array(
			'fulfillment_id'       => $event->fulfillment_id(),
			'event_type'           => $event->event_type(),
			'actor_type'           => $event->actor()->type(),
			'actor_id'             => $event->actor()->id(),
			'actor_label_snapshot' => $event->actor()->label(),
			'payload'              => $event->payload(),
			'prev_hash'            => $prev_hash,
			'hash'                 => $hash,
			'created_at'           => $event->occurred_at(),
		);

		return $hash;
	}

	public function last_hash_for_fulfillment( int $fulfillment_id ): ?string {
		$matching = $this->timeline_for_fulfillment( $fulfillment_id );

		if ( array() === $matching ) {
			return null;
		}

		return (string) end( $matching )['hash'];
	}

	public function timeline_for_fulfillment( int $fulfillment_id ): array {
		return array_values(
			array_filter(
				$this->rows,
				static fn( array $row ): bool => $row['fulfillment_id'] === $fulfillment_id
			)
		);
	}

	public function timeline_page_for_fulfillment( int $fulfillment_id, int $page, int $per_page ): EventTimelinePage {
		$matching = $this->timeline_for_fulfillment( $fulfillment_id );
		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );

		return new EventTimelinePage(
			array_slice( $matching, ( $page - 1 ) * $per_page, $per_page ),
			count( $matching ),
			$page,
			$per_page
		);
	}

	public function recent_for_fulfillment( int $fulfillment_id, int $limit ): array {
		return array_slice( $this->timeline_for_fulfillment( $fulfillment_id ), -1 * max( 1, $limit ) );
	}

	/**
	 * Test helper: every event appended, across every fulfillment.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		return $this->rows;
	}

	public function count_state_entries_since( string $state, DateTimeImmutable $since ): int {
		return count(
			array_filter(
				$this->rows,
				static fn( array $row ): bool => 'fulfillment.state_changed' === $row['event_type']
					&& $since <= $row['created_at']
					&& ( $row['payload']['to'] ?? null ) === $state
			)
		);
	}
}
