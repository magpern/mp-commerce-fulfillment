<?php
/**
 * Database-backed document generation record repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

use DateTimeImmutable;
use MPCF\Domain\Document\DocumentRecord;
use MPCF\Domain\Repository\DocumentRepository;

/**
 * The only class that reads or writes `mpcf_documents`. Append-only —
 * exposes insert + reads, no update or delete.
 *
 * Index note (M4-A): `KEY fulfillment_id (fulfillment_id)` and
 * `KEY doc_type (doc_type)` already exist. `list_for_fulfillment` is
 * covered by fulfillment_id. `latest_for_fulfillment_and_type` may want an
 * additive composite `(fulfillment_id, doc_type, created_at)` in M4-D if
 * history volume grows — not required for M4-A; no schema change now.
 */
final class WpdbDocumentRepository implements DocumentRepository {

	/**
	 * Records a rendered document and returns its assigned id.
	 *
	 * @param DocumentRecord $record A record built by {@see DocumentRecord::create()}.
	 */
	public function insert( DocumentRecord $record ): int {
		global $wpdb;

		$table = Schema::table( Schema::DOCUMENTS );

		$wpdb->insert(
			$table,
			array(
				'fulfillment_id'   => $record->fulfillment_id(),
				'doc_type'         => $record->doc_type(),
				'template_version' => $record->template_version(),
				'file_path'        => $record->file_path(),
				'rendered_by'      => $record->rendered_by(),
				'created_at'       => $record->created_at()->format( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Loads one document by id, or null when missing.
	 *
	 * @param int $document_id Document id.
	 */
	public function get( int $document_id ): ?DocumentRecord {
		global $wpdb;

		$table = Schema::table( Schema::DOCUMENTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a fixed schema literal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $document_id ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Lists documents for a fulfillment, newest first.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @return list<DocumentRecord>
	 */
	public function list_for_fulfillment( int $fulfillment_id ): array {
		global $wpdb;

		$table = Schema::table( Schema::DOCUMENTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a fixed schema literal.
		$sql = "SELECT * FROM {$table} WHERE fulfillment_id = %d ORDER BY created_at DESC, id DESC";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal with placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $fulfillment_id ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $this->hydrate( $row );
			}
		}

		return $out;
	}

	/**
	 * Latest document of a given type for a fulfillment, or null.
	 *
	 * @param int    $fulfillment_id Fulfillment id.
	 * @param string $doc_type       Document type key.
	 */
	public function latest_for_fulfillment_and_type( int $fulfillment_id, string $doc_type ): ?DocumentRecord {
		global $wpdb;

		$table = Schema::table( Schema::DOCUMENTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a fixed schema literal.
		$sql = "SELECT * FROM {$table} WHERE fulfillment_id = %d AND doc_type = %s ORDER BY created_at DESC, id DESC LIMIT 1";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal with placeholders only.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $fulfillment_id, $doc_type ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Searches document history with optional filters.
	 *
	 * @param array<string, mixed> $filters Search filters.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function search( array $filters ): array {
		global $wpdb;

		$docs = Schema::table( Schema::DOCUMENTS );
		$fuls = Schema::table( Schema::FULFILLMENTS );

		$where  = array( '1=1' );
		$params = array();

		$doc_type = isset( $filters['doc_type'] ) ? sanitize_key( (string) $filters['doc_type'] ) : '';
		if ( '' !== $doc_type ) {
			$where[]  = 'd.doc_type = %s';
			$params[] = $doc_type;
		}

		$date_from = isset( $filters['date_from'] ) ? (string) $filters['date_from'] : '';
		if ( '' !== $date_from && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$where[]  = 'd.created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}

		$date_to = isset( $filters['date_to'] ) ? (string) $filters['date_to'] : '';
		if ( '' !== $date_to && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$where[]  = 'd.created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		$search = isset( $filters['search'] ) ? trim( (string) $filters['search'] ) : '';
		if ( '' !== $search ) {
			if ( ctype_digit( $search ) ) {
				$where[]  = '(d.fulfillment_id = %d OR f.order_id = %d OR f.order_number_snapshot LIKE %s)';
				$params[] = (int) $search;
				$params[] = (int) $search;
				$params[] = '%' . $wpdb->esc_like( $search ) . '%';
			} else {
				$where[]  = 'f.order_number_snapshot LIKE %s';
				$params[] = '%' . $wpdb->esc_like( $search ) . '%';
			}
		}

		$where_sql = implode( ' AND ', $where );
		$limit     = isset( $filters['limit'] ) ? max( 1, min( 100, (int) $filters['limit'] ) ) : 50;
		$offset    = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are fixed schema literals.
		$count_sql = "SELECT COUNT(*) FROM {$docs} d INNER JOIN {$fuls} f ON f.id = d.fulfillment_id WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders prepared below.
		$total = (int) ( array() === $params ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are fixed schema literals.
		$list_sql    = "SELECT d.*, f.order_number_snapshot, f.order_id FROM {$docs} d INNER JOIN {$fuls} f ON f.id = d.fulfillment_id WHERE {$where_sql} ORDER BY d.created_at DESC, d.id DESC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $limit, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders prepared below.
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );

		$items = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$record  = $this->hydrate( $row );
				$items[] = array(
					'id'               => (int) $record->id(),
					'fulfillment_id'   => $record->fulfillment_id(),
					'order_id'         => (int) ( $row['order_id'] ?? 0 ),
					'order_number'     => (string) ( $row['order_number_snapshot'] ?? '' ),
					'doc_type'         => $record->doc_type(),
					'template_version' => $record->template_version(),
					'file_path'        => $record->file_path(),
					'stored'           => null !== $record->file_path(),
					'rendered_by'      => $record->rendered_by(),
					'created_at'       => $record->created_at()->format( 'c' ),
				);
			}
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Hydrates a database row into a DocumentRecord.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	private function hydrate( array $row ): DocumentRecord {
		return DocumentRecord::from_array(
			array(
				'id'               => (int) $row['id'],
				'fulfillment_id'   => (int) $row['fulfillment_id'],
				'doc_type'         => (string) $row['doc_type'],
				'template_version' => (string) $row['template_version'],
				'file_path'        => isset( $row['file_path'] ) && null !== $row['file_path'] && '' !== $row['file_path']
					? (string) $row['file_path']
					: null,
				'rendered_by'      => (int) $row['rendered_by'],
				'created_at'       => new DateTimeImmutable( (string) $row['created_at'] ),
			)
		);
	}
}
