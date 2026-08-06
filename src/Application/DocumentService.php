<?php
/**
 * Orchestrates document assembly, rendering, storage, and the audit record.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use DateTimeImmutable;
use MPCF\Documents\BrandingSnapshot;
use MPCF\Documents\DocumentRendererInterface;
use MPCF\Documents\DocumentTypeRegistry;
use MPCF\Documents\TemplateRegistry;
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
use MPCF\Engine\DocumentAssembler\PickingListAssembler;
use MPCF\Engine\DocumentAssembler\WavePickingListAssembler;
use MPCF\Infrastructure\Files\ProtectedDocumentStore;
use MPCF\Settings;
use Throwable;

/**
 * Architecture Plan §10 / M4: the only orchestrator of assemble → render →
 * store → record → audit. Extends the M2 packing-slip pipeline — does not
 * replace it. {@see DocumentPipelineGuardTest} asserts no other class calls
 * a renderer.
 *
 * Filesystem and database writes are not one atomic transaction. Compensation:
 * - render failure → no DB row, no artifact
 * - storage failure → no DB row, no artifact, no success event
 * - DB insert failure after file write → delete orphan artifact
 * - audit event failure after successful insert → document row + file remain
 *   (append-only; matches M2/M4-A; `stored=true` only appears when file + row
 *   both succeeded)
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
	 * Blog/site name fallback when branding store name is empty.
	 *
	 * @var string
	 */
	private string $blog_name_fallback;

	/**
	 * Document-type registry.
	 *
	 * @var DocumentTypeRegistry
	 */
	private DocumentTypeRegistry $types;

	/**
	 * Plugin settings (branding).
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Protected HTML storage.
	 *
	 * @var ProtectedDocumentStore
	 */
	private ProtectedDocumentStore $store;

	/**
	 * Template resolution (version determination).
	 *
	 * @var TemplateRegistry
	 */
	private TemplateRegistry $templates;

	/**
	 * Builds the service.
	 *
	 * @param FulfillmentRepository       $fulfillments       Fulfillment lookup.
	 * @param FulfillmentItemRepository   $items              Line item snapshots.
	 * @param OrderSource                 $orders             Owning order reads.
	 * @param ShippingService             $shipping           Package reads.
	 * @param DocumentRendererInterface   $renderer           Format-neutral renderer.
	 * @param DocumentRepository          $documents          Document record persistence.
	 * @param EventRepository             $events             Audit log persistence.
	 * @param EventDispatcher             $dispatcher         In-process event dispatch.
	 * @param Clock                       $clock              Source of "now".
	 * @param string                      $blog_name_fallback Site name fallback.
	 * @param DocumentTypeRegistry|null   $types              Document-type registry.
	 * @param Settings|null               $settings           Plugin settings.
	 * @param ProtectedDocumentStore|null $store             Protected HTML store.
	 * @param TemplateRegistry|null       $templates          Template registry.
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
		string $blog_name_fallback,
		?DocumentTypeRegistry $types = null,
		?Settings $settings = null,
		?ProtectedDocumentStore $store = null,
		?TemplateRegistry $templates = null
	) {
		$this->fulfillments       = $fulfillments;
		$this->items              = $items;
		$this->orders             = $orders;
		$this->shipping           = $shipping;
		$this->renderer           = $renderer;
		$this->documents          = $documents;
		$this->events             = $events;
		$this->dispatcher         = $dispatcher;
		$this->clock              = $clock;
		$this->blog_name_fallback = $blog_name_fallback;
		$this->types              = $types ?? new DocumentTypeRegistry();
		$this->settings           = $settings ?? new Settings( array() );
		$this->store              = $store ?? new ProtectedDocumentStore( sys_get_temp_dir() . '/mpcf-docs-' . getmypid() );
		$this->templates          = $templates ?? new TemplateRegistry();
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
	 * Renders a combined wave picking list (Part X). Print-only — does not
	 * insert a fulfillment-scoped document row (wave docs are not
	 * fulfillment-anchored in M8).
	 *
	 * @param \MPCF\Domain\Wave\Wave $wave  Wave.
	 * @param array<string, mixed>   $walk  Walk model.
	 * @param Actor                  $actor Operator.
	 */
	public function render_wave_picking_list( \MPCF\Domain\Wave\Wave $wave, array $walk, Actor $actor ): DocumentOutcome {
		$type = $this->types->get( WavePickingListAssembler::DOC_TYPE );

		if ( null === $type ) {
			return DocumentOutcome::failed( 'unknown_document_type', 'Wave picking list is not registered.' );
		}

		$branding = BrandingSnapshot::capture( $this->settings, $this->blog_name_fallback );
		$store    = (string) ( $branding['store_name'] ?? $this->blog_name_fallback );
		$model    = WavePickingListAssembler::assemble( $wave, $walk, $store, $branding, $type->template_version() );
		$now      = $this->clock->now();
		$model    = $model->with_render_meta(
			$this->templates->template_version( $type->id(), $type->template_version() ),
			$wave->state(),
			$now,
			(int) ( $actor->id() ?? 0 ),
			$branding,
			$this->renderer->format()
		);

		$html = $this->renderer->render( $model );

		if ( null === $html || '' === $html ) {
			return DocumentOutcome::failed( 'render_failed', 'Wave picking list render produced no HTML.' );
		}

		$event = DomainEvent::global_event(
			'document.rendered',
			$actor,
			$now,
			array(
				'doc_type'         => WavePickingListAssembler::DOC_TYPE,
				'wave_id'          => (int) $wave->id(),
				'template_version' => $model->template_version(),
				'stored'           => false,
			)
		);
		$this->events->append( $event, null );
		$this->dispatcher->dispatch( $event );

		return DocumentOutcome::succeeded(
			$html,
			0,
			array(
				'document_type'    => WavePickingListAssembler::DOC_TYPE,
				'template_version' => $model->template_version(),
				'stored'           => false,
				'wave_id'          => (int) $wave->id(),
			)
		);
	}

	/**
	 * Assembles, renders, stores, and records one document for a fulfillment.
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

		$branding = BrandingSnapshot::capture( $this->settings, $this->blog_name_fallback );
		$model    = $this->assemble( $type, $fulfillment, $order, $branding );

		if ( $model instanceof DocumentOutcome ) {
			return $model;
		}

		$now              = $this->clock->now();
		$template_version = $this->templates->template_version( $type->id(), $type->template_version() );
		$model            = $model->with_render_meta(
			$template_version,
			$fulfillment->state(),
			$now,
			(int) ( $actor->id() ?? 0 ),
			$branding,
			$this->renderer->format()
		);

		/**
		 * Filters the assembled document model before rendering.
		 *
		 * Must return a {@see DocumentModel}. Invalid returns are rejected;
		 * the filtered model is then treated as frozen for this render.
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

		$file_path = null;
		$stored    = false;
		$bytes     = strlen( $html );
		$sha256    = hash( 'sha256', $html );
		$mime      = $this->renderer->mime_type();

		if ( DocumentType::STORAGE_STORE === $type->storage_policy() ) {
			try {
				$result    = $this->store->write( $fulfillment_id, $filtered->doc_type(), $html, $now );
				$file_path = $result->relative_path();
				$bytes     = $result->byte_size();
				$sha256    = $result->sha256();
				$mime      = $result->mime_type();
				$stored    = true;
			} catch ( Throwable $e ) {
				return DocumentOutcome::failed(
					'storage_failed',
					'Unable to store the rendered document: ' . $e->getMessage()
				);
			}
		}

		try {
			$document_id = $this->documents->insert(
				DocumentRecord::create(
					$fulfillment_id,
					$filtered->doc_type(),
					$template_version,
					$file_path,
					(int) ( $actor->id() ?? 0 ),
					$now
				)
			);
		} catch ( Throwable $e ) {
			if ( $stored && null !== $file_path ) {
				$this->store->delete_relative( $file_path );
			}

			return DocumentOutcome::failed(
				'persistence_failed',
				'Unable to record the document: ' . $e->getMessage()
			);
		}

		if ( $document_id <= 0 ) {
			if ( $stored && null !== $file_path ) {
				$this->store->delete_relative( $file_path );
			}

			return DocumentOutcome::failed( 'persistence_failed', 'Unable to record the document.' );
		}

		// Never claim stored=true unless file write and metadata persistence both succeeded.
		$this->record_event(
			$fulfillment_id,
			'document.rendered',
			$actor,
			$now,
			array(
				'document_id'      => $document_id,
				'doc_type'         => $filtered->doc_type(),
				'template_version' => $template_version,
				'renderer'         => $this->renderer->format(),
				'renderer_format'  => $filtered->renderer_format(),
				'stored'           => $stored,
				'file_path'        => $file_path,
				'mime'             => $mime,
				'bytes'            => $bytes,
				'sha256'           => $sha256,
			)
		);

		return DocumentOutcome::succeeded(
			$html,
			$document_id,
			array(
				'document_type'    => $filtered->doc_type(),
				'template_version' => $template_version,
				'stored'           => $stored,
				'file_path'        => $file_path,
				'mime'             => $mime,
				'bytes'            => $bytes,
				'sha256'           => $sha256,
				'file_available'   => $stored && null !== $file_path,
			)
		);
	}

	/**
	 * Invokes the assembler named by the type definition.
	 *
	 * @param DocumentType               $type        Document type.
	 * @param Fulfillment                $fulfillment Fulfillment.
	 * @param \MPCF\Domain\OrderSnapshot $order       Owning order snapshot.
	 * @param array<string, mixed>       $branding    Branding snapshot.
	 * @return DocumentModel|DocumentOutcome
	 */
	private function assemble( DocumentType $type, Fulfillment $fulfillment, $order, array $branding ) {
		$store_name = isset( $branding['store_name'] ) ? (string) $branding['store_name'] : $this->blog_name_fallback;

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
					$store_name,
					$branding,
					$type->template_version()
				);

			case PickingListAssembler::DOC_TYPE:
				return PickingListAssembler::assemble(
					$fulfillment,
					$order,
					$this->items->find_for_fulfillment( (int) $fulfillment->id() ),
					$store_name,
					$branding,
					$type->template_version()
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
