<?php
/**
 * Pure, render-agnostic assembled document data.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Document;

use DateTimeImmutable;

/**
 * Architecture Plan §10: "Assembly != rendering." An `Engine\DocumentAssembler\*`
 * produces one of these from fulfillment/order/item/package/branding snapshots
 * — fully unit-testable, no HTML. A `Documents\*Renderer` consumes it.
 *
 * M4 contract: this model IS the render-time snapshot. Historical documents
 * must never depend on later fulfillment, order, branding, or template
 * changes. M4-A establishes the fields; M4-B persists the canonical HTML
 * artifact (and may serialize this model alongside it). Fresh prints always
 * assemble a new model; historical reprints must not reassemble under the
 * same document id (M4-D / `source_document_id` seam).
 */
final class DocumentModel {

	/**
	 * Document type registry key.
	 *
	 * @var string
	 */
	private string $doc_type;

	/**
	 * The fulfillment this document describes.
	 *
	 * @var int
	 */
	private int $fulfillment_id;

	/**
	 * Order number, as the order platform displays it.
	 *
	 * @var string
	 */
	private string $order_number;

	/**
	 * Customer display name.
	 *
	 * @var string
	 */
	private string $customer_name;

	/**
	 * Ship-to address, formatted as display lines.
	 *
	 * @var list<string>
	 */
	private array $ship_to_lines;

	/**
	 * Store name, from settings/site identity (branding snapshot field).
	 *
	 * @var string
	 */
	private string $store_name;

	/**
	 * Line items: each `array{sku:string,name:string,qty_ordered:int}`.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $items;

	/**
	 * Package summaries: each `array{seq:int,weight_grams:int|null,length_mm:int|null,width_mm:int|null,height_mm:int|null,tracking_number:string|null}`.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $packages;

	/**
	 * Code 128 barcode payload (the order number).
	 *
	 * @var string
	 */
	private string $barcode_payload;

	/**
	 * Fulfillment workflow state captured at assemble time.
	 *
	 * @var string
	 */
	private string $fulfillment_state;

	/**
	 * Explicit template version (from the document-type definition).
	 *
	 * @var string
	 */
	private string $template_version;

	/**
	 * Branding snapshot placeholder (M4-B fills address/footer/logo).
	 *
	 * @var array<string, mixed>
	 */
	private array $branding;

	/**
	 * Render timestamp when known (set by DocumentService before render).
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $rendered_at;

	/**
	 * Operator user id when known (0 = system).
	 *
	 * @var int
	 */
	private int $rendered_by;

	/**
	 * Assembles a document model.
	 *
	 * @param string                           $doc_type          Document type registry key.
	 * @param int                              $fulfillment_id    The fulfillment this document describes.
	 * @param string                           $order_number      Order number.
	 * @param string                           $customer_name     Customer display name.
	 * @param array<int, string>               $ship_to_lines     Ship-to address, as display lines.
	 * @param string                           $store_name        Store name.
	 * @param array<int, array<string, mixed>> $items             Line items.
	 * @param array<int, array<string, mixed>> $packages          Package summaries.
	 * @param string                           $barcode_payload   Code 128 barcode payload.
	 * @param string                           $fulfillment_state Fulfillment state snapshot.
	 * @param string                           $template_version  Template version.
	 * @param array<string, mixed>             $branding          Branding snapshot placeholder.
	 * @param DateTimeImmutable|null           $rendered_at       Render timestamp.
	 * @param int                              $rendered_by       Operator user id.
	 */
	public function __construct(
		string $doc_type,
		int $fulfillment_id,
		string $order_number,
		string $customer_name,
		array $ship_to_lines,
		string $store_name,
		array $items,
		array $packages,
		string $barcode_payload,
		string $fulfillment_state = '',
		string $template_version = '1',
		array $branding = array(),
		?DateTimeImmutable $rendered_at = null,
		int $rendered_by = 0
	) {
		$this->doc_type          = $doc_type;
		$this->fulfillment_id    = $fulfillment_id;
		$this->order_number      = $order_number;
		$this->customer_name     = $customer_name;
		$this->ship_to_lines     = $ship_to_lines;
		$this->store_name        = $store_name;
		$this->items             = $items;
		$this->packages          = $packages;
		$this->barcode_payload   = $barcode_payload;
		$this->fulfillment_state = $fulfillment_state;
		$this->template_version  = $template_version;
		$this->branding          = $branding;
		$this->rendered_at       = $rendered_at;
		$this->rendered_by       = $rendered_by;
	}

	/**
	 * Returns a copy with render-time meta filled by DocumentService.
	 *
	 * @param string               $template_version Explicit template version.
	 * @param string               $fulfillment_state Fulfillment state snapshot.
	 * @param DateTimeImmutable    $rendered_at       Render timestamp.
	 * @param int                  $rendered_by       Operator user id.
	 * @param array<string, mixed> $branding        Branding snapshot (optional merge).
	 */
	public function with_render_meta(
		string $template_version,
		string $fulfillment_state,
		DateTimeImmutable $rendered_at,
		int $rendered_by,
		array $branding = array()
	): self {
		$merged_branding = array_merge(
			array( 'store_name' => $this->store_name ),
			$this->branding,
			$branding
		);

		return new self(
			$this->doc_type,
			$this->fulfillment_id,
			$this->order_number,
			$this->customer_name,
			$this->ship_to_lines,
			$this->store_name,
			$this->items,
			$this->packages,
			$this->barcode_payload,
			$fulfillment_state,
			$template_version,
			$merged_branding,
			$rendered_at,
			$rendered_by
		);
	}

	/**
	 * Document type registry key.
	 */
	public function doc_type(): string {
		return $this->doc_type;
	}

	/**
	 * The fulfillment this document describes.
	 */
	public function fulfillment_id(): int {
		return $this->fulfillment_id;
	}

	/**
	 * Order number, as the order platform displays it.
	 */
	public function order_number(): string {
		return $this->order_number;
	}

	/**
	 * Customer display name.
	 */
	public function customer_name(): string {
		return $this->customer_name;
	}

	/**
	 * Ship-to address, formatted as display lines.
	 *
	 * @return list<string>
	 */
	public function ship_to_lines(): array {
		return $this->ship_to_lines;
	}

	/**
	 * Store name.
	 */
	public function store_name(): string {
		return $this->store_name;
	}

	/**
	 * Line items.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Package summaries.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function packages(): array {
		return $this->packages;
	}

	/**
	 * Code 128 barcode payload (the order number).
	 */
	public function barcode_payload(): string {
		return $this->barcode_payload;
	}

	/**
	 * Fulfillment workflow state captured at assemble/render time.
	 */
	public function fulfillment_state(): string {
		return $this->fulfillment_state;
	}

	/**
	 * Explicit template version.
	 */
	public function template_version(): string {
		return $this->template_version;
	}

	/**
	 * Branding snapshot placeholder.
	 *
	 * @return array<string, mixed>
	 */
	public function branding(): array {
		return $this->branding;
	}

	/**
	 * Render timestamp when known.
	 */
	public function rendered_at(): ?DateTimeImmutable {
		return $this->rendered_at;
	}

	/**
	 * Operator user id when known.
	 */
	public function rendered_by(): int {
		return $this->rendered_by;
	}
}
