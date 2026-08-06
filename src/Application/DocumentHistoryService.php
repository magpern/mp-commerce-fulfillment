<?php
/**
 * Application service for document history, content streaming, and reprint.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use DateTimeImmutable;
use MPCF\Domain\Clock;
use MPCF\Domain\Document\DocumentRecord;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\Repository\DocumentRepository;
use MPCF\Domain\Repository\EventRepository;
use MPCF\Infrastructure\Files\ProtectedDocumentStore;

/**
 * M4-D: history listing, exact historical content reads, and reprint audit.
 * Never reassembles under an existing document id. Lineage for reprints lives
 * in the `document.reprinted` event payload (`source_document_id`) — no
 * schema column required.
 */
final class DocumentHistoryService {

	/**
	 * Maximum fulfillments per Queue bulk picking-list print.
	 */
	public const BULK_PICKING_CAP = 25;

	/**
	 * Document repository.
	 *
	 * @var DocumentRepository
	 */
	private DocumentRepository $documents;

	/**
	 * Protected HTML file store.
	 *
	 * @var ProtectedDocumentStore
	 */
	private ProtectedDocumentStore $store;

	/**
	 * Event repository.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * In-process event dispatcher.
	 *
	 * @var EventDispatcher
	 */
	private EventDispatcher $dispatcher;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Fresh document renderer (bulk print).
	 *
	 * @var DocumentService
	 */
	private DocumentService $renderer;

	/**
	 * Builds the history service.
	 *
	 * @param DocumentRepository     $documents  Document records.
	 * @param ProtectedDocumentStore $store      Protected HTML store.
	 * @param EventRepository        $events     Audit log.
	 * @param EventDispatcher        $dispatcher In-process dispatch.
	 * @param Clock                  $clock      Source of now.
	 * @param DocumentService        $renderer   Fresh render for bulk print.
	 */
	public function __construct(
		DocumentRepository $documents,
		ProtectedDocumentStore $store,
		EventRepository $events,
		EventDispatcher $dispatcher,
		Clock $clock,
		DocumentService $renderer
	) {
		$this->documents  = $documents;
		$this->store      = $store;
		$this->events     = $events;
		$this->dispatcher = $dispatcher;
		$this->clock      = $clock;
		$this->renderer   = $renderer;
	}

	/**
	 * Searches document history.
	 *
	 * @param array<string, mixed> $filters doc_type, search, date_from, date_to, limit, offset.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function search( array $filters ): array {
		return $this->documents->search( $filters );
	}

	/**
	 * Loads one document record or null.
	 *
	 * @param int $document_id Document id.
	 */
	public function get( int $document_id ): ?DocumentRecord {
		return $this->documents->get( $document_id );
	}

	/**
	 * Reads exact stored HTML for a document (no audit event).
	 *
	 * @param int $document_id Document id.
	 * @return array{ok: bool, code?: string, message?: string, html?: string, mime?: string, record?: DocumentRecord}
	 */
	public function read_content( int $document_id ): array {
		$record = $this->documents->get( $document_id );

		if ( null === $record ) {
			return array(
				'ok'      => false,
				'code'    => 'not_found',
				'message' => 'Document not found.',
			);
		}

		$path = $record->file_path();

		if ( null === $path || '' === $path ) {
			return array(
				'ok'      => false,
				'code'    => 'missing_artifact',
				'message' => 'This document has no stored HTML artifact.',
				'record'  => $record,
			);
		}

		$absolute = $this->store->absolute_path( $path );

		if ( null === $absolute || ! is_readable( $absolute ) ) {
			return array(
				'ok'      => false,
				'code'    => 'missing_artifact',
				'message' => 'Stored document file is missing or inaccessible.',
				'record'  => $record,
			);
		}

		$html = file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Trusted path from repository + protected root.

		if ( ! is_string( $html ) ) {
			return array(
				'ok'      => false,
				'code'    => 'corrupt_artifact',
				'message' => 'Stored document file could not be read.',
				'record'  => $record,
			);
		}

		return array(
			'ok'     => true,
			'html'   => $html,
			'mime'   => 'text/html; charset=UTF-8',
			'record' => $record,
		);
	}

	/**
	 * Streams exact stored HTML and appends document.reprinted.
	 *
	 * @param int   $document_id Document id.
	 * @param Actor $actor       Operator.
	 * @return array{ok: bool, code?: string, message?: string, html?: string, mime?: string, record?: DocumentRecord}
	 */
	public function reprint( int $document_id, Actor $actor ): array {
		$result = $this->read_content( $document_id );

		if ( ! $result['ok'] ) {
			return $result;
		}

		/**
		 * Document record for the reprint.
		 *
		 * @var DocumentRecord $record
		 */
		$record = $result['record'];
		$now    = $this->clock->now();

		$this->record_event(
			$record->fulfillment_id(),
			'document.reprinted',
			$actor,
			$now,
			array(
				'source_document_id' => (int) $record->id(),
				'doc_type'           => $record->doc_type(),
				'template_version'   => $record->template_version(),
				'file_path'          => $record->file_path(),
			)
		);

		return $result;
	}

	/**
	 * Capped bulk fresh picking-list renders for Queue rows.
	 *
	 * @param int[]                 $fulfillment_ids Selected ids.
	 * @param Actor                 $actor           Operator.
	 * @param callable(string):bool $can             Capability check.
	 * @return array{
	 *   succeeded: list<array{fulfillment_id: int, document_id: int, html: string}>,
	 *   failed: array<int, string>,
	 *   skipped_cap: list<int>,
	 *   combined_html: string
	 * }
	 */
	public function bulk_print_picking_lists( array $fulfillment_ids, Actor $actor, callable $can ): array {
		$ids = array_values( array_unique( array_map( 'intval', $fulfillment_ids ) ) );
		$ids = array_filter( $ids, static fn( int $id ): bool => $id > 0 );

		$capped   = array_slice( $ids, 0, self::BULK_PICKING_CAP );
		$overflow = array_slice( $ids, self::BULK_PICKING_CAP );

		$succeeded = array();
		$failed    = array();
		$parts     = array();

		foreach ( $overflow as $id ) {
			$failed[ $id ] = sprintf(
				'Skipped — bulk picking-list print is capped at %d fulfillments per request.',
				self::BULK_PICKING_CAP
			);
		}

		foreach ( $capped as $id ) {
			$outcome = $this->renderer->render(
				$id,
				'picking_list',
				array(
					'actor' => $actor,
					'can'   => $can,
				)
			);

			if ( ! $outcome->is_success() ) {
				$failed[ $id ] = (string) $outcome->failure_message();
				continue;
			}

			$html        = (string) $outcome->html();
			$succeeded[] = array(
				'fulfillment_id' => $id,
				'document_id'    => (int) $outcome->document_id(),
				'html'           => $html,
			);
			$parts[]     = '<div class="mpcf-bulk-doc" style="page-break-after: always;">' . $html . '</div>';
		}

		$combined = '';
		if ( array() !== $parts ) {
			$combined = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Picking lists</title></head><body>'
				. implode( '', $parts )
				. '</body></html>';
		}

		return array(
			'succeeded'     => $succeeded,
			'failed'        => $failed,
			'skipped_cap'   => $overflow,
			'combined_html' => $combined,
		);
	}

	/**
	 * Appends a document audit event and dispatches it.
	 *
	 * @param int                  $fulfillment_id Fulfillment id.
	 * @param string               $event_type     Event type.
	 * @param Actor                $actor          Actor.
	 * @param DateTimeImmutable    $now            When.
	 * @param array<string, mixed> $payload        Payload.
	 */
	private function record_event( int $fulfillment_id, string $event_type, Actor $actor, DateTimeImmutable $now, array $payload ): void {
		$event     = DomainEvent::for_fulfillment( $fulfillment_id, $event_type, $actor, $now, $payload );
		$prev_hash = $this->events->last_hash_for_fulfillment( $fulfillment_id );

		$this->events->append( $event, $prev_hash );
		$this->dispatcher->dispatch( $event );
	}
}
