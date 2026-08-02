<?php
/**
 * Orchestrates document assembly, rendering, and the audit record.
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
use MPCF\Domain\OrderSource;
use MPCF\Domain\Repository\DocumentRepository;
use MPCF\Domain\Repository\EventRepository;
use MPCF\Domain\Repository\FulfillmentItemRepository;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Documents\HtmlRenderer;
use MPCF\Engine\DocumentAssembler\PackingSlipAssembler;

/**
 * Architecture Plan §10: the only orchestrator of assemble → render →
 * record → audit — {@see DocumentPipelineGuardTest} asserts no other class
 * calls {@see HtmlRenderer::render()}, which is what makes "documents
 * printed" a reliable audit fact rather than something that could happen
 * ad hoc from an admin screen or a REST controller.
 */
final class DocumentService {

	/**
	 * The template version every render through this service currently
	 * produces — recorded per-document so a future reprint can always say
	 * exactly what the original looked like, even after this constant
	 * changes.
	 */
	private const TEMPLATE_VERSION = '1';

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
	 * Turns an assembled document model into HTML.
	 *
	 * @var HtmlRenderer
	 */
	private HtmlRenderer $renderer;

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
	 * Store display name — plain data resolved by the composition root
	 * from site identity; this class never reads that itself (invariant I6).
	 *
	 * @var string
	 */
	private string $store_name;

	/**
	 * Builds the service.
	 *
	 * @param FulfillmentRepository     $fulfillments Fulfillment lookup.
	 * @param FulfillmentItemRepository $items        Line item snapshots.
	 * @param OrderSource               $orders       A live read of the owning order, for its ship-to address.
	 * @param ShippingService           $shipping     Package reads.
	 * @param HtmlRenderer              $renderer     Turns an assembled document model into HTML.
	 * @param DocumentRepository        $documents    Document generation record persistence.
	 * @param EventRepository           $events       Audit log persistence.
	 * @param EventDispatcher           $dispatcher   In-process event dispatch.
	 * @param Clock                     $clock        Source of "now".
	 * @param string                    $store_name   Store display name.
	 */
	public function __construct(
		FulfillmentRepository $fulfillments,
		FulfillmentItemRepository $items,
		OrderSource $orders,
		ShippingService $shipping,
		HtmlRenderer $renderer,
		DocumentRepository $documents,
		EventRepository $events,
		EventDispatcher $dispatcher,
		Clock $clock,
		string $store_name
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
	}

	/**
	 * Assembles, renders, and records a packing slip for one fulfillment.
	 *
	 * @param int   $fulfillment_id Fulfillment to render a packing slip for.
	 * @param Actor $actor          Who is rendering it.
	 */
	public function render_packing_slip( int $fulfillment_id, Actor $actor ): DocumentOutcome {
		$fulfillment = $this->fulfillments->find( $fulfillment_id );

		if ( null === $fulfillment ) {
			return DocumentOutcome::failed( 'not_found', "No fulfillment exists with id {$fulfillment_id}." );
		}

		$order = $this->orders->find( $fulfillment->order_id() );

		if ( null === $order ) {
			return DocumentOutcome::failed( 'not_found', 'The owning order no longer exists.' );
		}

		$packages = array();

		foreach ( $this->shipping->list_for_fulfillment( $fulfillment_id ) as $row ) {
			$packages = array_merge( $packages, $row['packages'] );
		}

		$model = PackingSlipAssembler::assemble(
			$fulfillment,
			$order,
			$this->items->find_for_fulfillment( $fulfillment_id ),
			$packages,
			$this->store_name
		);

		$html = $this->renderer->render( $model );

		if ( null === $html ) {
			return DocumentOutcome::failed( 'invalid_payload', "No template is bundled for document type \"{$model->doc_type()}\"." );
		}

		$now         = $this->clock->now();
		$document_id = $this->documents->insert(
			DocumentRecord::create( $fulfillment_id, $model->doc_type(), self::TEMPLATE_VERSION, null, (int) ( $actor->id() ?? 0 ), $now )
		);

		$this->record_event(
			$fulfillment_id,
			'document.rendered',
			$actor,
			$now,
			array(
				'document_id'      => $document_id,
				'doc_type'         => $model->doc_type(),
				'template_version' => self::TEMPLATE_VERSION,
			)
		);

		return DocumentOutcome::succeeded( $html, $document_id );
	}

	/**
	 * Appends one hash-chained audit event and dispatches it — the same
	 * shape {@see ShippingService::record_event()} uses.
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
