<?php
/**
 * Orchestrates document assembly, rendering, and the audit record.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use DateTimeImmutable;
use MPCF\Documents\DocumentRendererInterface;
use MPCF\Documents\DocumentTypeRegistry;
use MPCF\Domain\Clock;
use MPCF\Domain\Document\DocumentModel;
use MPCF\Domain\Document\DocumentRecord;
use MPCF\Domain\Document\DocumentStagePolicy;
use MPCF\Domain\Document\DocumentType;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\OrderSource;
use MPCF\Domain\Repository\DocumentRepository;
use MPCF\Domain\Repository\EventRepository;
use MPCF\Domain\Repository\FulfillmentItemRepository;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Engine\DocumentAssembler\PackingSlipAssembler;

/**
 * Architecture Plan §10 / M4-A: the only orchestrator of assemble → render →
 * record → audit. Extends the M2 packing-slip pipeline — does not replace it.
 * {@see DocumentPipelineGuardTest} asserts no other class calls a renderer.
 */
final class DocumentService {

	/**
	 * Fulfillment lookup.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

	/**
	 * Line item snapshots.
	 *
	 * @var FulfillmentItemRepository
	 */
	private FulfillmentItemRepository $items;

	/**
	 * A live read of the owning order, for its ship-to address.
	 *
	 * @var OrderSource
	 */
	private OrderSource $orders;

	/**
	 * Package reads.
	 *
	 * @var ShippingService
	 */
	private ShippingService $shipping;

	/**
	 * Format-neutral renderer (HTML canonical).
	 *
	 * @var DocumentRendererInterface
	 */
	private DocumentRendererInterface $renderer;

	/**
	 * Document generation record persistence.
	 *
	 * @var DocumentRepository
	 */
	private DocumentRepository $documents;

	/**
	 * Audit log persistence.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * In-process event dispatch.
	 *
	 * @var EventDispatcher
	 */
	private EventDispatcher $dispatcher;

	/**
	 * Source of "now".
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Store display name — plain data resolved by the composition root.
	 *
	 * @var string
	 */
	private string $store_name;

	/**
	 * Document-type registry.
	 *
	 * @var DocumentTypeRegistry
	 */
	private DocumentTypeRegistry $types;

	/**
	 * Builds the service.
	 *
	 * @param FulfillmentRepository     $fulfillments Fulfillment lookup.
	 * @param FulfillmentItemRepository $items        Line item snapshots.
	 * @param OrderSource               $orders       Owning order reads.
	 * @param ShippingService           $shipping     Package reads.
	 * @param DocumentRendererInterface $renderer     Format-neutral renderer.
	 * @param DocumentRepository        $documents    Document record persistence.
	 * @param EventRepository           $events       Audit log persistence.
	 * @param EventDispatcher           $dispatcher   In-process event dispatch.
	 * @param Clock                     $clock        Source of "now".
	 * @param string                    $store_name   Store display name.
	 * @param DocumentTypeRegistry|null $types        Document-type registry.
	 */
	public function __construct(
		FulfillmentRepository $fulfillments,
		FulfillmentItemRepository $items,
		OrderSource $orders,
		ShippingService $shipping,
		DocumentRendererInterface $renderer,
		DocumentRepository $documents,
		EventRepository $events,
		EventDispatcher $dispatcher,
		Clock $clock,
		string $store_name,
		?DocumentTypeRegistry $types = null
	) {
		$this->fulfillments = $fulfillments;
		$this->items        = $items;
		$this->orders       = $orders;
		$this->shipping     = $shipping;
		$this->renderer     = $renderer;
		$this->documents    = $documents;
		$this->events       = $events;
		$this->dispatcher   = $dispatcher;
		$this->clock        = $clock;
		$this->store_name   = $store_name;
		$this->types        = $types ?? new DocumentTypeRegistry();
	}

	/**
	 * Compatibility wrapper — delegates to {@see render()} so M2 callers keep
	 * working through a single pipeline.
	 *
	 * @param int   $fulfillment_id Fulfillment to render a packing slip for.
	 * @param Actor $actor          Who is rendering it.
	 */
	public function render_packing_slip( int $fulfillment_id, Actor $actor ): DocumentOutcome {
		return $this->render(
			$fulfillment_id,
			'packing_slip',
			array(
				'actor' => $actor,
			)
		);
	}

	/**
	 * Assembles, renders, and records one document for a fulfillment.
	 *
	 * Options:
	 * - `actor` (Actor, required for audit; defaults to system)
	 * - `can` (callable(string $capability): bool) optional capability check
	 *
	 * @param int                  $fulfillment_id Fulfillment id.
	 * @param string               $document_type  Registry key.
	 * @param array<string, mixed> $options        Render options.
	 */
	public function render( int $fulfillment_id, string $document_type, array $options = array() ): DocumentOutcome {
		$actor = $options['actor'] ?? Actor::system();
		if ( ! $actor instanceof Actor ) {
			$actor = Actor::system();
		}

		$type = $this->types->get( $document_type );

		if ( null === $type ) {
			return DocumentOutcome::failed(
				'unknown_document_type',
				sprintf( 'Unknown or invalid document type "%s".', $document_type )
			);
		}

		if ( isset( $options['can'] ) && is_callable( $options['can'] ) && ! (bool) $options['can']( $type->capability() ) ) {
			return DocumentOutcome::failed(
				'forbidden',
				sprintf( 'Missing capability to render %s.', $type->label() )
			);
		}

		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return DocumentOutcome::failed( 'not_found', "No fulfillment exists with id {$fulfillment_id}." );
		}

		if ( ! DocumentStagePolicy::allows( $type, $fulfillment ) ) {
			return DocumentOutcome::failed(
				(string) DocumentStagePolicy::denial_code( $type, $fulfillment ),
				DocumentStagePolicy::denial_message( $type, $fulfillment )
			);
		}

		$order = $this->orders->find( $fulfillment->order_id() );

		if ( null === $order ) {
			return DocumentOutcome::failed( 'not_found', 'The owning order no longer exists.' );
		}

		$model = $this->assemble( $type, $fulfillment, $order );

		if ( $model instanceof DocumentOutcome ) {
			return $model;
		}

		$now   = $this->clock->now();
		$model = $model->with_render_meta(
			$type->template_version(),
			$fulfillment->state(),
			$now,
			(int) ( $actor->id() ?? 0 ),
			array( 'store_name' => $this->store_name )
		);

		/**
		 * Filters the assembled document model before rendering.
		 *
		 * Must return a {@see DocumentModel}. Invalid returns are rejected.
		 *
		 * @since 0.4.0
		 *
		 * @param DocumentModel $model       Assembled model.
		 * @param DocumentType  $type        Resolved document type.
		 * @param Fulfillment   $fulfillment Fulfillment being rendered.
		 */
		$filtered = apply_filters( 'mpcf_document_model', $model, $type, $fulfillment );

		if ( ! $filtered instanceof DocumentModel ) {
			return DocumentOutcome::failed( 'invalid_payload', 'mpcf_document_model filter must return a DocumentModel.' );
		}

		if ( $filtered->doc_type() !== $type->id() ) {
			return DocumentOutcome::failed( 'invalid_payload', 'mpcf_document_model filter must not change doc_type.' );
		}

		$html = $this->renderer->render( $filtered );

		if ( null === $html ) {
			return DocumentOutcome::failed(
				'invalid_payload',
				sprintf( 'No template is available for document type "%s".', $filtered->doc_type() )
			);
		}

		// M4-A: storage_policy remains print (file_path NULL). Protected HTML
		// storage is M4-B. Fresh render always inserts a new immutable row —
		// historical reprint with source_document_id is M4-D.
		$document_id = $this->documents->insert(
			DocumentRecord::create(
				$fulfillment_id,
				$filtered->doc_type(),
				$type->template_version(),
				null,
				(int) ( $actor->id() ?? 0 ),
				$now
			)
		);

		$this->record_event(
			$fulfillment_id,
			'document.rendered',
			$actor,
			$now,
			array(
				'document_id'      => $document_id,
				'doc_type'         => $filtered->doc_type(),
				'template_version' => $type->template_version(),
				'renderer'         => $type->renderer(),
			)
		);

		return DocumentOutcome::succeeded( $html, $document_id );
	}

	/**
	 * Invokes the assembler named by the type definition.
	 *
	 * @param DocumentType               $type        Document type.
	 * @param Fulfillment                $fulfillment Fulfillment.
	 * @param \MPCF\Domain\OrderSnapshot $order       Owning order snapshot.
	 * @return DocumentModel|DocumentOutcome
	 */
	private function assemble( DocumentType $type, Fulfillment $fulfillment, $order ) {
		switch ( $type->assembler() ) {
			case PackingSlipAssembler::DOC_TYPE:
				$packages = array();

				foreach ( $this->shipping->list_for_fulfillment( (int) $fulfillment->id() ) as $row ) {
					$packages = array_merge( $packages, $row['packages'] );
				}

				return PackingSlipAssembler::assemble(
					$fulfillment,
					$order,
					$this->items->find_for_fulfillment( (int) $fulfillment->id() ),
					$packages,
					$this->store_name
				);

			case 'picking_list':
				// Type is registered in M4-A; assembler/template land in M4-B.
				return DocumentOutcome::failed(
					'not_implemented',
					'Picking list rendering ships in M4-B.'
				);

			default:
				return DocumentOutcome::failed(
					'unknown_document_type',
					sprintf( 'No assembler is registered for "%s".', $type->assembler() )
				);
		}
	}

	/**
	 * Appends one hash-chained audit event and dispatches it.
	 *
	 * @param int                  $fulfillment_id Fulfillment the event belongs to.
	 * @param string               $event_type     Event type to record.
	 * @param Actor                $actor          Who caused this event.
	 * @param DateTimeImmutable    $now            When this event occurred.
	 * @param array<string, mixed> $payload        Event payload.
	 */
	private function record_event( int $fulfillment_id, string $event_type, Actor $actor, DateTimeImmutable $now, array $payload ): void {
		$event     = DomainEvent::for_fulfillment( $fulfillment_id, $event_type, $actor, $now, $payload );
		$prev_hash = $this->events->last_hash_for_fulfillment( $fulfillment_id );

		$this->events->append( $event, $prev_hash );
		$this->dispatcher->dispatch( $event );
	}
}
