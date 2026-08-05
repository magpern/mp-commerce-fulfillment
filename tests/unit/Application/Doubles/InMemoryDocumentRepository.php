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
	 * @param array<string, mixed> $filters Search filters.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function search( array $filters ): array {
		$items = array();

		foreach ( $this->rows as $row ) {
			if ( isset( $filters['doc_type'] ) && '' !== (string) $filters['doc_type'] && $row->doc_type() !== (string) $filters['doc_type'] ) {
				continue;
			}

			$items[] = array(
				'id'               => (int) $row->id(),
				'fulfillment_id'   => $row->fulfillment_id(),
				'order_id'         => 0,
				'order_number'     => '',
				'doc_type'         => $row->doc_type(),
				'template_version' => $row->template_version(),
				'file_path'        => $row->file_path(),
				'stored'           => null !== $row->file_path(),
				'rendered_by'      => $row->rendered_by(),
				'created_at'       => $row->created_at()->format( 'c' ),
			);
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return strcmp( (string) $b['created_at'], (string) $a['created_at'] );
			}
		);

		$total  = count( $items );
		$limit  = isset( $filters['limit'] ) ? max( 1, min( 100, (int) $filters['limit'] ) ) : 50;
		$offset = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;

		return array(
			'items' => array_slice( $items, $offset, $limit ),
			'total' => $total,
		);
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
