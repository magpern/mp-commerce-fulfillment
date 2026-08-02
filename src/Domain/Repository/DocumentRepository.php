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
 * reliable fact (§10).
 */
interface DocumentRepository {

	/**
	 * Records a rendered document and returns its assigned id.
	 *
	 * @param DocumentRecord $record A record built by {@see DocumentRecord::create()}.
	 */
	public function insert( DocumentRecord $record ): int;
}
