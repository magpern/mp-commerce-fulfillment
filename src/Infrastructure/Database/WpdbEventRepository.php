<?php
/**
 * Database-backed, append-only audit event repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

use DateTimeImmutable;
use MPCF\Domain\Event\Canonicalizer;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\Repository\EventRepository;

/**
 * The only class that reads or writes `mpcf_events`. Exposes exactly
 * `append()` and readers (invariant I5) — no method here issues an
 * `UPDATE` or a `DELETE` against this table, and none ever may.
 */
final class WpdbEventRepository implements EventRepository {

	/**
	 * Appends one event to the chain, computing its hash from `$prev_hash`
	 * and the event's canonical payload.
	 *
	 * @param DomainEvent $event     Event to append.
	 * @param string|null $prev_hash Previous event's hash for this fulfillment, or null for its first event.
	 */
	public function append( DomainEvent $event, ?string $prev_hash ): string {
		global $wpdb;

		$table = Schema::table( Schema::EVENTS );
		$hash  = Canonicalizer::hash( $prev_hash, $event->hashable_payload() );

		$wpdb->insert(
			$table,
			array(
				'fulfillment_id'       => $event->fulfillment_id(),
				'event_type'           => $event->event_type(),
				'actor_type'           => $event->actor()->type(),
				'actor_id'             => $event->actor()->id(),
				'actor_label_snapshot' => $event->actor()->label(),
				'payload'              => wp_json_encode( $event->payload() ),
				'prev_hash'            => $prev_hash,
				'hash'                 => $hash,
				'created_at'           => $event->occurred_at()->format( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $hash;
	}

	/**
	 * The hash of the most recently appended event for a fulfillment.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function last_hash_for_fulfillment( int $fulfillment_id ): ?string {
		global $wpdb;

		$table = Schema::table( Schema::EVENTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
		$hash = $wpdb->get_var( $wpdb->prepare( "SELECT hash FROM {$table} WHERE fulfillment_id = %d ORDER BY id DESC LIMIT 1", $fulfillment_id ) );

		return null === $hash ? null : (string) $hash;
	}

	/**
	 * The full chain for one fulfillment, oldest first, with each row's
	 * `payload` decoded back into an array.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	public function timeline_for_fulfillment( int $fulfillment_id ): array {
		global $wpdb;

		$table = Schema::table( Schema::EVENTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE fulfillment_id = %d ORDER BY id ASC", $fulfillment_id ), ARRAY_A );

		return array_map(
			static function ( array $row ): array {
				$row['payload'] = json_decode( (string) $row['payload'], true ) ?? array();

				return $row;
			},
			$rows ?? array()
		);
	}

	/**
	 * How many `fulfillment.state_changed` events since `$since` moved a
	 * fulfillment into `$state`. Filters on the two indexed columns
	 * (`event_type`, `created_at`) in SQL, then reads the small resulting
	 * set's JSON payload in PHP — the payload's `to` field is not itself
	 * indexed, but "events since midnight" is bounded regardless of total
	 * table size, unlike the Queue's own hot path.
	 *
	 * @param string            $state State a fulfillment entered.
	 * @param DateTimeImmutable $since Only count events at or after this moment.
	 */
	public function count_state_entries_since( string $state, DateTimeImmutable $since ): int {
		global $wpdb;

		$table = Schema::table( Schema::EVENTS );
		$rows  = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
				"SELECT payload FROM {$table} WHERE event_type = %s AND created_at >= %s",
				'fulfillment.state_changed',
				$since->format( 'Y-m-d H:i:s' )
			)
		);

		$count = 0;

		foreach ( $rows ?? array() as $payload_json ) {
			$payload = json_decode( (string) $payload_json, true );

			if ( is_array( $payload ) && ( $payload['to'] ?? null ) === $state ) {
				++$count;
			}
		}

		return $count;
	}
}
