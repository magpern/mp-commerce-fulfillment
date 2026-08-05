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

	public function get( int $document_id ): ?DocumentRecord {
		return $this->rows[ $document_id ] ?? null;
	}

	public function list_for_fulfillment( int $fulfillment_id ): array {
		$matches = array();

		foreach ( $this->rows as $row ) {
			if ( $row->fulfillment_id() === $fulfillment_id ) {
				$matches[] = $row;
			}
		}

		usort(
			$matches,
			static function ( DocumentRecord $a, DocumentRecord $b ): int {
				$cmp = $b->created_at() <=> $a->created_at();

				return 0 !== $cmp ? $cmp : ( (int) $b->id() <=> (int) $a->id() );
			}
		);

		return $matches;
	}

	public function latest_for_fulfillment_and_type( int $fulfillment_id, string $doc_type ): ?DocumentRecord {
		foreach ( $this->list_for_fulfillment( $fulfillment_id ) as $row ) {
			if ( $row->doc_type() === $doc_type ) {
				return $row;
			}
		}

		return null;
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
