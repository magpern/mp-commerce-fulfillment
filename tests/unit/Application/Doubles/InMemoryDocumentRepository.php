<?php
/**
 * In-memory test double for the document generation record repository.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Doubles;

use MPCF\Domain\Document\DocumentRecord;
use MPCF\Domain\Repository\DocumentRepository;

/**
 * In-memory document record store; no real database involved.
 */
final class InMemoryDocumentRepository implements DocumentRepository {

	/**
	 * @var array<int, DocumentRecord>
	 */
	private array $rows = array();

	/**
	 * @var int
	 */
	private int $next_id = 1;

	public function insert( DocumentRecord $record ): int {
		$id                = $this->next_id++;
		$this->rows[ $id ] = DocumentRecord::from_array( array( 'id' => $id ) + $record->to_array() );

		return $id;
	}

	/**
	 * Test helper: every record inserted, in insertion order.
	 *
	 * @return list<DocumentRecord>
	 */
	public function all(): array {
		return array_values( $this->rows );
	}
}
