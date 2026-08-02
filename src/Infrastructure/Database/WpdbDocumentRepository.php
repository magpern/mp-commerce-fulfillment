<?php
/**
 * Database-backed document generation record repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

use MPCF\Domain\Document\DocumentRecord;
use MPCF\Domain\Repository\DocumentRepository;

/**
 * The only class that reads or writes `mpcf_documents`. Append-only,
 * mirroring {@see WpdbEventRepository}'s own contract (I5's discipline
 * extended to documents, §10) — exposes `insert()` only, no update or
 * delete.
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
}
