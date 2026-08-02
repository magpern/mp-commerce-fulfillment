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
