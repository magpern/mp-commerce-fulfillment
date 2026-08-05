<?php
/**
 * Persistence contract for the document generation record.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Repository;

use MPCF\Domain\Document\DocumentRecord;

/**
 * Implemented in Infrastructure ({@see \MPCF\Infrastructure\Database\WpdbDocumentRepository}),
 * confined there per invariant I7. Append-only, mirroring
 * {@see EventRepository}'s own contract — "documents printed" must stay a
 * reliable fact (§10). No delete API — documents are immutable audit records.
 *
 * M4-D reprint lineage (`source_document_id`) and content streaming are
 * deferred; M4-A only adds the read methods history UI will need.
 */
interface DocumentRepository {

	/**
	 * Records a rendered document and returns its assigned id.
	 *
	 * @param DocumentRecord $record A record built by {@see DocumentRecord::create()}.
	 */
	public function insert( DocumentRecord $record ): int;

	/**
	 * Loads one document by id, or null when missing.
	 *
	 * @param int $document_id Document id.
	 */
	public function get( int $document_id ): ?DocumentRecord;

	/**
	 * Lists documents for a fulfillment, newest first.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 * @return list<DocumentRecord>
	 */
	public function list_for_fulfillment( int $fulfillment_id ): array;

	/**
	 * Latest document of a given type for a fulfillment, or null.
	 *
	 * @param int    $fulfillment_id Fulfillment id.
	 * @param string $doc_type       Document type key.
	 */
	public function latest_for_fulfillment_and_type( int $fulfillment_id, string $doc_type ): ?DocumentRecord;
}
