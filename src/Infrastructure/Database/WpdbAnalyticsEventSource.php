<?php
/**
 * Wpdb-backed analytics event/fulfillment/wave reader.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

use DateTimeImmutable;
use MPCF\Domain\Repository\AnalyticsEventSource;
use MPCF\Engine\Analytics\CounterCalculator;
use MPCF\Engine\Analytics\DurationCalculator;

/**
 * Read-only source for AnalyticsEngine. Never mutates workflow state.
 */
final class WpdbAnalyticsEventSource implements AnalyticsEventSource {

	/**
	 * Raw counter tallies for [from, to) UTC window and warehouse.
	 *
	 * @param DateTimeImmutable $from         Inclusive window start.
	 * @param DateTimeImmutable $to           Exclusive window end.
	 * @param int               $warehouse_id Warehouse scope.
	 * @return array<string, mixed>
	 */
	public function counter_raw( DateTimeImmutable $from, DateTimeImmutable $to, int $warehouse_id ): array {
		global $wpdb;

		$base   = CounterCalculator::empty();
		$from_s = $from->format( 'Y-m-d H:i:s' );
		$to_s   = $to->format( 'Y-m-d H:i:s' );

		$base['fulfillments']['created'] = $this->count_event_type( 'fulfillment.created', $from_s, $to_s, $warehouse_id );
		$base['fulfillments']['packed']  = $this->count_state_to( 'packed', $from_s, $to_s, $warehouse_id );
		$base['fulfillments']['shipped'] = $this->count_state_to( 'shipped', $from_s, $to_s, $warehouse_id );

		$base['scans']['total']       = $this->count_event_types( array( 'scan.item_picked', 'scan.item_packed' ), $from_s, $to_s, $warehouse_id );
		$base['scans']['corrections'] = $this->count_event_type( 'scan.corrected', $from_s, $to_s, $warehouse_id );

		$base['photos']['captured'] = $this->count_event_type( 'photo.captured', $from_s, $to_s, $warehouse_id );
		$base['photos']['purged']   = $this->count_event_type( 'photo.purged', $from_s, $to_s, $warehouse_id );

		$base['notifications']['sent']       = $this->count_event_type( 'notification.sent', $from_s, $to_s, $warehouse_id );
		$base['notifications']['failed']     = $this->count_event_type( 'notification.failed', $from_s, $to_s, $warehouse_id );
		$base['notifications']['suppressed'] = $this->count_event_type( 'notification.suppressed', $from_s, $to_s, $warehouse_id );

		$base['documents']['rendered']  = $this->count_event_type( 'document.rendered', $from_s, $to_s, $warehouse_id );
		$base['documents']['reprinted'] = $this->count_event_type( 'document.reprinted', $from_s, $to_s, $warehouse_id );

		$base['exceptions']['state_entries'] = $this->count_state_to_any( array( 'problem', 'waiting' ), $from_s, $to_s, $warehouse_id );

		$base['top_reasons']['rejection']    = $this->tally_state_reasons( array( 'problem', 'waiting' ), $from_s, $to_s, $warehouse_id );
		$base['top_reasons']['notification'] = $this->tally_payload_field( 'notification.failed', 'reason', $from_s, $to_s, $warehouse_id );
		$base['top_reasons']['scan']         = $this->tally_payload_field( 'scan.corrected', 'reason', $from_s, $to_s, $warehouse_id );
		$base['top_reasons']['guard']        = $this->tally_payload_field( 'fulfillment.state_changed', 'guard_id', $from_s, $to_s, $warehouse_id );

		$wave          = $this->wave_counters( $from_s, $to_s, $warehouse_id );
		$base['waves'] = array_merge( $base['waves'], $wave );

		return $base;
	}

	/**
	 * Hop duration samples in seconds for [from, to), keyed by hop id.
	 *
	 * @param DateTimeImmutable $from         Inclusive window start.
	 * @param DateTimeImmutable $to           Exclusive window end.
	 * @param int               $warehouse_id Warehouse scope.
	 * @return array<string, list<float>>
	 */
	public function duration_samples( DateTimeImmutable $from, DateTimeImmutable $to, int $warehouse_id ): array {
		$by_hop = array();
		foreach ( DurationCalculator::hop_keys() as $key ) {
			$by_hop[ $key ] = array();
		}

		$pairs = array(
			'queued_to_picking' => array( 'queued', 'picking' ),
			'picking_to_picked' => array( 'picking', 'picked' ),
			'picked_to_packing' => array( 'picked', 'packing' ),
			'packing_to_packed' => array( 'packing', 'packed' ),
			'packed_to_shipped' => array( 'packed', 'shipped' ),
		);

		$from_s = $from->format( 'Y-m-d H:i:s' );
		$to_s   = $to->format( 'Y-m-d H:i:s' );

		$timelines = $this->state_timelines( $from_s, $to_s, $warehouse_id );

		foreach ( $timelines as $events ) {
			$entered    = array();
			$queued_at  = null;
			$shipped_at = null;
			$shipped_s  = null;

			foreach ( $events as $ev ) {
				$to_state = (string) ( $ev['to'] ?? '' );
				$at       = (string) ( $ev['at'] ?? '' );
				if ( '' === $to_state || '' === $at ) {
					continue;
				}
				$ts = strtotime( $at . ' UTC' );
				if ( false === $ts ) {
					continue;
				}
				$from_state = (string) ( $ev['from'] ?? '' );
				if ( '' !== $from_state && isset( $entered[ $from_state ] ) && $at >= $from_s && $at < $to_s ) {
					foreach ( $pairs as $hop => $pair ) {
						if ( $pair[0] === $from_state && $pair[1] === $to_state ) {
							$by_hop[ $hop ][] = (float) ( $ts - $entered[ $from_state ] );
						}
					}
				}
				$entered[ $to_state ] = $ts;
				if ( 'queued' === $to_state && null === $queued_at ) {
					$queued_at = $ts;
				}
				if ( 'shipped' === $to_state ) {
					$shipped_at = $ts;
					$shipped_s  = $at;
				}
			}

			if ( null !== $queued_at && null !== $shipped_at && null !== $shipped_s && $shipped_s >= $from_s && $shipped_s < $to_s ) {
				$by_hop['queued_to_shipped'][] = (float) ( $shipped_at - $queued_at );
			}
		}

		return $by_hop;
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
		global $wpdb;

		if ( array() === $open_states ) {
			return array();
		}

		$table = Schema::table( Schema::FULFILLMENTS );
		$in    = implode( ',', array_fill( 0, count( $open_states ), '%s' ) );
		$sql   = "SELECT state_entered_at FROM {$table} WHERE warehouse_id = %d AND state IN ({$in})";
		$args  = array_merge( array( $warehouse_id ), $open_states );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders built from counted states.
		$rows   = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		$now_ts = $now->getTimestamp();
		$ages   = array();

		foreach ( $rows ?? array() as $entered ) {
			$ts = strtotime( (string) $entered . ' UTC' );
			if ( false === $ts ) {
				continue;
			}
			$ages[] = max( 0, $now_ts - $ts );
		}

		return $ages;
	}

	/**
	 * Count fulfillments currently in the given states.
	 *
	 * @param array $states       State keys to count.
	 * @param int   $warehouse_id Warehouse scope.
	 */
	public function count_in_states( array $states, int $warehouse_id ): int {
		global $wpdb;

		if ( array() === $states ) {
			return 0;
		}

		$table = Schema::table( Schema::FULFILLMENTS );
		$in    = implode( ',', array_fill( 0, count( $states ), '%s' ) );
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE warehouse_id = %d AND state IN ({$in})";
		$args  = array_merge( array( $warehouse_id ), $states );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Max event id observed before `$to` (for rebuild auditing).
	 *
	 * @param DateTimeImmutable $to Exclusive window end.
	 */
	public function max_event_id_through( DateTimeImmutable $to ): ?int {
		global $wpdb;

		$table = Schema::table( Schema::EVENTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM {$table} WHERE created_at < %s", $to->format( 'Y-m-d H:i:s' ) ) );

		return null === $id ? null : (int) $id;
	}

	/**
	 * Counts events of a single type in the window.
	 *
	 * @param string $type         Event type.
	 * @param string $from_s       Inclusive start datetime.
	 * @param string $to_s         Exclusive end datetime.
	 * @param int    $warehouse_id Warehouse scope.
	 */
	private function count_event_type( string $type, string $from_s, string $to_s, int $warehouse_id ): int {
		return $this->count_event_types( array( $type ), $from_s, $to_s, $warehouse_id );
	}

	/**
	 * Counts events whose type is in `$types`.
	 *
	 * @param array  $types        Event types.
	 * @param string $from_s       Inclusive start datetime.
	 * @param string $to_s         Exclusive end datetime.
	 * @param int    $warehouse_id Warehouse scope.
	 */
	private function count_event_types( array $types, string $from_s, string $to_s, int $warehouse_id ): int {
		global $wpdb;

		$events = Schema::table( Schema::EVENTS );
		$ful    = Schema::table( Schema::FULFILLMENTS );
		$in     = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql    = "SELECT COUNT(*) FROM {$events} e
			LEFT JOIN {$ful} f ON f.id = e.fulfillment_id
			WHERE e.event_type IN ({$in})
			AND e.created_at >= %s AND e.created_at < %s
			AND (e.fulfillment_id IS NULL OR f.warehouse_id = %d)";
		$args   = array_merge( $types, array( $from_s, $to_s, $warehouse_id ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Counts state_changed transitions into `$state`.
	 *
	 * @param string $state        Target state key.
	 * @param string $from_s       Inclusive start datetime.
	 * @param string $to_s         Exclusive end datetime.
	 * @param int    $warehouse_id Warehouse scope.
	 */
	private function count_state_to( string $state, string $from_s, string $to_s, int $warehouse_id ): int {
		return $this->count_state_to_any( array( $state ), $from_s, $to_s, $warehouse_id );
	}

	/**
	 * Counts state_changed transitions into any of `$states`.
	 *
	 * @param array  $states       Target state keys.
	 * @param string $from_s       Inclusive start datetime.
	 * @param string $to_s         Exclusive end datetime.
	 * @param int    $warehouse_id Warehouse scope.
	 */
	private function count_state_to_any( array $states, string $from_s, string $to_s, int $warehouse_id ): int {
		global $wpdb;

		$events = Schema::table( Schema::EVENTS );
		$ful    = Schema::table( Schema::FULFILLMENTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table names.
		$sql = "SELECT e.payload FROM {$events} e INNER JOIN {$ful} f ON f.id = e.fulfillment_id WHERE e.event_type = %s AND e.created_at >= %s AND e.created_at < %s AND f.warehouse_id = %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built above with Schema table names; values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, 'fulfillment.state_changed', $from_s, $to_s, $warehouse_id ), ARRAY_A );

		$count = 0;
		foreach ( $rows ?? array() as $row ) {
			$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
			$to      = is_array( $payload ) ? ( $payload['to'] ?? null ) : null;
			if ( in_array( $to, $states, true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Tallies rejection reasons for transitions into `$to_states`.
	 *
	 * @param array  $to_states     Target state keys.
	 * @param string $from_s        Inclusive start datetime.
	 * @param string $to_s          Exclusive end datetime.
	 * @param int    $warehouse_id  Warehouse scope.
	 * @return array<string, int>
	 */
	private function tally_state_reasons( array $to_states, string $from_s, string $to_s, int $warehouse_id ): array {
		global $wpdb;

		$events = Schema::table( Schema::EVENTS );
		$ful    = Schema::table( Schema::FULFILLMENTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table names.
		$sql = "SELECT e.payload FROM {$events} e INNER JOIN {$ful} f ON f.id = e.fulfillment_id WHERE e.event_type = %s AND e.created_at >= %s AND e.created_at < %s AND f.warehouse_id = %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built above with Schema table names; values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, 'fulfillment.state_changed', $from_s, $to_s, $warehouse_id ), ARRAY_A );

		$tallies = array();
		foreach ( $rows ?? array() as $row ) {
			$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
			if ( ! is_array( $payload ) ) {
				continue;
			}
			if ( ! in_array( $payload['to'] ?? null, $to_states, true ) ) {
				continue;
			}
			$reason             = (string) ( $payload['reason'] ?? '(unknown)' );
			$tallies[ $reason ] = ( $tallies[ $reason ] ?? 0 ) + 1;
		}

		return $tallies;
	}

	/**
	 * Tallies a payload field for events of `$event_type`.
	 *
	 * @param string $event_type   Event type.
	 * @param string $field        Payload field name.
	 * @param string $from_s       Inclusive start datetime.
	 * @param string $to_s         Exclusive end datetime.
	 * @param int    $warehouse_id Warehouse scope.
	 * @return array<string, int>
	 */
	private function tally_payload_field( string $event_type, string $field, string $from_s, string $to_s, int $warehouse_id ): array {
		global $wpdb;

		$events = Schema::table( Schema::EVENTS );
		$ful    = Schema::table( Schema::FULFILLMENTS );
		$sql    = "SELECT e.payload FROM {$events} e
			LEFT JOIN {$ful} f ON f.id = e.fulfillment_id
			WHERE e.event_type = %s AND e.created_at >= %s AND e.created_at < %s
			AND (e.fulfillment_id IS NULL OR f.warehouse_id = %d)";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $event_type, $from_s, $to_s, $warehouse_id ), ARRAY_A );

		$tallies = array();
		foreach ( $rows ?? array() as $row ) {
			$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
			if ( ! is_array( $payload ) ) {
				continue;
			}
			$val = $payload[ $field ] ?? null;
			if ( null === $val || '' === $val ) {
				continue;
			}
			$key             = (string) $val;
			$tallies[ $key ] = ( $tallies[ $key ] ?? 0 ) + 1;
		}

		return $tallies;
	}

	/**
	 * Aggregates terminal wave counters for the window.
	 *
	 * @param string $from_s       Inclusive start datetime.
	 * @param string $to_s         Exclusive end datetime.
	 * @param int    $warehouse_id Warehouse scope.
	 * @return array<string, float|int>
	 */
	private function wave_counters( string $from_s, string $to_s, int $warehouse_id ): array {
		global $wpdb;

		$waves   = Schema::table( Schema::WAVES );
		$members = Schema::table( Schema::WAVE_MEMBERS );
		$items   = Schema::table( Schema::FULFILLMENT_ITEMS );

		$out = array(
			'completed'              => 0,
			'abandoned'              => 0,
			'member_sum'             => 0,
			'item_sum'               => 0,
			'line_sum'               => 0,
			'duration_sum_seconds'   => 0.0,
			'paused_sum_seconds'     => 0.0,
			'completion_pct_sum'     => 0.0,
			'completion_pct_samples' => 0,
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
		$sql = "SELECT * FROM {$waves} WHERE warehouse_id = %d AND state IN ('completed','abandoned') AND ((state = 'completed' AND completed_at >= %s AND completed_at < %s) OR (state = 'abandoned' AND abandoned_at >= %s AND abandoned_at < %s))";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built above with Schema table name; values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $warehouse_id, $from_s, $to_s, $from_s, $to_s ), ARRAY_A );

		foreach ( $rows ?? array() as $row ) {
			$state = (string) $row['state'];
			if ( 'completed' === $state ) {
				++$out['completed'];
			} else {
				++$out['abandoned'];
			}

			$wave_id = (int) $row['id'];
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
			$member_count       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$members} WHERE wave_id = %d", $wave_id ) );
			$out['member_sum'] += $member_count;

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
			$picked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$members} WHERE wave_id = %d AND picked_at IS NOT NULL", $wave_id ) );
			if ( $member_count > 0 ) {
				$out['completion_pct_sum'] += ( 100.0 * $picked / $member_count );
				++$out['completion_pct_samples'];
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table names.
			$line_sum         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$items} i INNER JOIN {$members} m ON m.fulfillment_id = i.fulfillment_id WHERE m.wave_id = %d", $wave_id ) );
			$out['line_sum'] += $line_sum;

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table names.
			$item_sum         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(i.qty_ordered),0) FROM {$items} i INNER JOIN {$members} m ON m.fulfillment_id = i.fulfillment_id WHERE m.wave_id = %d", $wave_id ) );
			$out['item_sum'] += $item_sum;

			$activated = $row['activated_at'] ?? null;
			$ended     = 'completed' === $state ? ( $row['completed_at'] ?? null ) : ( $row['abandoned_at'] ?? null );
			if ( $activated && $ended ) {
				$a = strtotime( (string) $activated . ' UTC' );
				$e = strtotime( (string) $ended . ' UTC' );
				if ( false !== $a && false !== $e && $e >= $a ) {
					$out['duration_sum_seconds'] += (float) ( $e - $a );
				}
			}
		}

		return $out;
	}

	/**
	 * Loads state-change timelines for fulfillments touched in the window.
	 *
	 * @param string $from_s       Inclusive start datetime.
	 * @param string $to_s         Exclusive end datetime.
	 * @param int    $warehouse_id Warehouse scope.
	 * @return array<int, list<array{from: string, to: string, at: string}>>
	 */
	private function state_timelines( string $from_s, string $to_s, int $warehouse_id ): array {
		global $wpdb;

		$events = Schema::table( Schema::EVENTS );
		$ful    = Schema::table( Schema::FULFILLMENTS );

		// Load state changes for fulfillments that had any change ending in window,
		// plus earlier events for those fulfillments to compute durations.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table names.
		$sql = "SELECT DISTINCT e.fulfillment_id FROM {$events} e INNER JOIN {$ful} f ON f.id = e.fulfillment_id WHERE e.event_type = %s AND e.created_at >= %s AND e.created_at < %s AND f.warehouse_id = %d AND e.fulfillment_id IS NOT NULL";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built above with Schema table names; values prepared.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, 'fulfillment.state_changed', $from_s, $to_s, $warehouse_id ) );

		$timelines = array();
		foreach ( $ids ?? array() as $fid ) {
			$fid = (int) $fid;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-built table name.
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT payload, created_at FROM {$events} WHERE fulfillment_id = %d AND event_type = %s AND created_at < %s ORDER BY id ASC", $fid, 'fulfillment.state_changed', $to_s ), ARRAY_A );
			$chain = array();
			foreach ( $rows ?? array() as $row ) {
				$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
				if ( ! is_array( $payload ) ) {
					continue;
				}
				$chain[] = array(
					'from' => (string) ( $payload['from'] ?? '' ),
					'to'   => (string) ( $payload['to'] ?? '' ),
					'at'   => (string) $row['created_at'],
				);
			}
			$timelines[ $fid ] = $chain;
		}

		return $timelines;
	}
}
