<?php
/**
 * Database-backed fulfillment repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

use DateTimeImmutable;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentQuery;
use MPCF\Domain\FulfillmentQueryResult;
use MPCF\Domain\Repository\FulfillmentRepository;

/**
 * The only class that reads or writes `mpcf_fulfillments`. `save()`
 * implements the optimistic lock: the `UPDATE` always advances `version`
 * in its own `SET` clause, so a matched row always reports at least one
 * affected row even when every other column happens to be unchanged —
 * MySQL's default "changed rows" accounting would otherwise mistake that
 * case for a lock conflict.
 */
final class WpdbFulfillmentRepository implements FulfillmentRepository {

	/**
	 * Finds a fulfillment by its own id.
	 *
	 * @param int $id Fulfillment id.
	 */
	public function find( int $id ): ?Fulfillment {
		global $wpdb;

		$table = Schema::table( Schema::FULFILLMENTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Finds a fulfillment by the order it was created from.
	 *
	 * @param int $order_id Order id.
	 */
	public function find_by_order_id( int $order_id ): ?Fulfillment {
		global $wpdb;

		$table = Schema::table( Schema::FULFILLMENTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC LIMIT 1", $order_id ), ARRAY_A );

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Every fulfillment created from a given order.
	 *
	 * @param int $order_id Order id.
	 */
	public function find_all_by_order_id( int $order_id ): array {
		global $wpdb;

		$table = Schema::table( Schema::FULFILLMENTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC", $order_id ), ARRAY_A );

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Inserts a brand-new fulfillment and returns its assigned id, or null if
	 * the `(order_id, order_source)` uniqueness constraint rejected it (see
	 * {@see Schema::fulfillments_order_unique_index_ddl()}) — a concurrent
	 * intake attempt for the same order having just won the race, not a
	 * genuine failure.
	 *
	 * @param Fulfillment $fulfillment A fulfillment built by {@see Fulfillment::intake()}.
	 */
	public function insert( Fulfillment $fulfillment ): ?int {
		global $wpdb;

		$table        = Schema::table( Schema::FULFILLMENTS );
		$completed_at = $fulfillment->completed_at();

		$result = $wpdb->insert(
			$table,
			array(
				'order_id'               => $fulfillment->order_id(),
				'order_source'           => $fulfillment->order_source(),
				'warehouse_id'           => $fulfillment->warehouse_id(),
				'workflow'               => $fulfillment->workflow(),
				'state'                  => $fulfillment->state(),
				'previous_state'         => $fulfillment->previous_state(),
				'return_to_state'        => $fulfillment->return_to_state(),
				'exception_reason'       => $fulfillment->exception_reason(),
				'priority'               => $fulfillment->priority(),
				'assignee_type'          => $fulfillment->assignee_type(),
				'assignee_id'            => $fulfillment->assignee_id(),
				'version'                => $fulfillment->version(),
				'order_number_snapshot'  => $fulfillment->order_number_snapshot(),
				'customer_name_snapshot' => $fulfillment->customer_name_snapshot(),
				'item_count'             => $fulfillment->item_count(),
				'created_at'             => $fulfillment->created_at()->format( 'Y-m-d H:i:s' ),
				'state_entered_at'       => $fulfillment->state_entered_at()->format( 'Y-m-d H:i:s' ),
				'completed_at'           => null === $completed_at ? null : $completed_at->format( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return null;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Persists a mutation to an existing fulfillment with an optimistic
	 * lock, conditioned on `$fulfillment`'s current in-memory `version`.
	 *
	 * @param Fulfillment $fulfillment Fulfillment to persist.
	 */
	public function save( Fulfillment $fulfillment ): bool {
		global $wpdb;

		$table        = Schema::table( Schema::FULFILLMENTS );
		$completed_at = $fulfillment->completed_at();

		$updated = $wpdb->update(
			$table,
			array(
				'state'            => $fulfillment->state(),
				'previous_state'   => $fulfillment->previous_state(),
				'return_to_state'  => $fulfillment->return_to_state(),
				'exception_reason' => $fulfillment->exception_reason(),
				'priority'         => $fulfillment->priority(),
				'assignee_type'    => $fulfillment->assignee_type(),
				'assignee_id'      => $fulfillment->assignee_id(),
				'state_entered_at' => $fulfillment->state_entered_at()->format( 'Y-m-d H:i:s' ),
				'completed_at'     => null === $completed_at ? null : $completed_at->format( 'Y-m-d H:i:s' ),
				'version'          => $fulfillment->version() + 1,
			),
			array(
				'id'      => $fulfillment->id(),
				'version' => $fulfillment->version(),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d' ),
			array( '%d', '%d' )
		);

		if ( $updated ) {
			$fulfillment->increment_version();

			return true;
		}

		return false;
	}

	/**
	 * Advances `version` by one, conditioned on `$expected_version` —
	 * the same optimistic-lock shape as {@see save()}, but touching no
	 * other column, for a non-workflow write (item batch, shipment/package
	 * mutation) that needs to advance the fulfillment's concurrency token
	 * without rewriting state columns it has no business rewriting.
	 *
	 * @param int $id               Fulfillment id.
	 * @param int $expected_version The version the caller last read.
	 */
	public function touch( int $id, int $expected_version ): bool {
		global $wpdb;

		$table = Schema::table( Schema::FULFILLMENTS );

		$updated = $wpdb->update(
			$table,
			array( 'version' => $expected_version + 1 ),
			array(
				'id'      => $id,
				'version' => $expected_version,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);

		return (bool) $updated;
	}

	/**
	 * A server-side paginated, filtered listing — the only method here that
	 * builds a dynamic `WHERE` clause, always against indexed columns
	 * ({@see where_clause()}).
	 *
	 * @param FulfillmentQuery $query Filter/sort/page.
	 */
	public function query( FulfillmentQuery $query ): FulfillmentQueryResult {
		global $wpdb;

		$table                        = Schema::table( Schema::FULFILLMENTS );
		list( $where, $where_params ) = $this->where_clause( $query );

		if ( array() === $where_params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table/$where are Schema-built/placeholder-only, never user input; there is nothing here for prepare() to bind.
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
		} else {
			$total = (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where's own %-placeholders are opaque to static analysis here but are real and bound via $where_params below.
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $where_params )
			);
		}

		$order_by = $this->safe_order_by( $query->order_by() );
		$order    = 'ASC' === strtoupper( $query->order() ) ? 'ASC' : 'DESC';

		$sql  = "SELECT * FROM {$table} {$where} ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $sql's only dynamic fragment is $where, built exclusively from %-placeholders (see where_clause()); $order_by/$order are validated against a fixed allowlist, never interpolated user input.
			$wpdb->prepare( $sql, array_merge( $where_params, array( $query->per_page(), $query->offset() ) ) ),
			ARRAY_A
		);

		return new FulfillmentQueryResult( array_map( array( $this, 'hydrate' ), $rows ?? array() ), $total, $query->page(), $query->per_page() );
	}

	/**
	 * Count of fulfillments whose state is one of `$states`.
	 *
	 * @param array<int, string> $states State keys to match.
	 */
	public function count_in_states( array $states ): int {
		global $wpdb;

		if ( array() === $states ) {
			return 0;
		}

		$table        = Schema::table( Schema::FULFILLMENTS );
		$placeholders = implode( ',', array_fill( 0, count( $states ), '%s' ) );

		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table/$placeholders are Schema-built/count-derived, never user input; the %s placeholders are real and bound via $states.
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE state IN ({$placeholders})", $states )
		);
	}

	/**
	 * Builds a `WHERE` clause and its bound parameters from a query's
	 * filters — every condition targets an indexed column
	 * (`state`/`assignee_id`/`id`/`state_entered_at`), per Architecture
	 * Plan §9.3's "no unindexed scan" rule for the Queue's hot path.
	 *
	 * @param FulfillmentQuery $query Filter to translate.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function where_clause( FulfillmentQuery $query ): array {
		$conditions = array();
		$params     = array();

		if ( array() !== $query->states() ) {
			$placeholders = implode( ',', array_fill( 0, count( $query->states() ), '%s' ) );
			$conditions[] = "state IN ({$placeholders})";
			array_push( $params, ...$query->states() );
		}

		$assignee = $query->assignee();

		if ( FulfillmentQuery::SENTINEL_UNASSIGNED === $assignee ) {
			$conditions[] = 'assignee_id IS NULL';
		} elseif ( null !== $assignee ) {
			$conditions[] = 'assignee_id = %d';
			$params[]     = (int) $assignee;
		}

		if ( null !== $query->fulfillment_ids() ) {
			if ( array() === $query->fulfillment_ids() ) {
				$conditions[] = '1 = 0'; // No candidate ids (e.g. an empty search result) -> no rows, never a malformed empty IN().
			} else {
				$placeholders = implode( ',', array_fill( 0, count( $query->fulfillment_ids() ), '%d' ) );
				$conditions[] = "id IN ({$placeholders})";
				array_push( $params, ...$query->fulfillment_ids() );
			}
		}

		if ( null !== $query->min_age_seconds() ) {
			$conditions[] = 'state_entered_at <= %s';
			$params[]     = gmdate( 'Y-m-d H:i:s', time() - $query->min_age_seconds() );
		}

		if ( array() === $conditions ) {
			return array( '', array() );
		}

		return array( 'WHERE ' . implode( ' AND ', $conditions ), $params );
	}

	/**
	 * Validates a caller-requested sort column against a fixed allowlist —
	 * never interpolates caller input directly into `ORDER BY`.
	 *
	 * @param string $column Requested column.
	 */
	private function safe_order_by( string $column ): string {
		return in_array( $column, array( 'created_at', 'state_entered_at', 'priority', 'id' ), true ) ? $column : 'created_at';
	}

	/**
	 * Assembles a fulfillment from one `ARRAY_A` row.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 */
	private function hydrate( array $row ): Fulfillment {
		return Fulfillment::from_array(
			array(
				'id'                     => (int) $row['id'],
				'order_id'               => (int) $row['order_id'],
				'order_source'           => (string) $row['order_source'],
				'warehouse_id'           => (int) $row['warehouse_id'],
				'workflow'               => (string) $row['workflow'],
				'state'                  => (string) $row['state'],
				'previous_state'         => $row['previous_state'],
				'return_to_state'        => $row['return_to_state'],
				'exception_reason'       => $row['exception_reason'],
				'priority'               => (int) $row['priority'],
				'assignee_type'          => $row['assignee_type'],
				'assignee_id'            => null === $row['assignee_id'] ? null : (int) $row['assignee_id'],
				'version'                => (int) $row['version'],
				'order_number_snapshot'  => (string) $row['order_number_snapshot'],
				'customer_name_snapshot' => (string) $row['customer_name_snapshot'],
				'item_count'             => (int) $row['item_count'],
				'created_at'             => new DateTimeImmutable( (string) $row['created_at'] ),
				'state_entered_at'       => new DateTimeImmutable( (string) $row['state_entered_at'] ),
				'completed_at'           => null === $row['completed_at'] ? null : new DateTimeImmutable( (string) $row['completed_at'] ),
			)
		);
	}
}
