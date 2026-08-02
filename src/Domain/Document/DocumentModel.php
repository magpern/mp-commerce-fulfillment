<?php
/**
 * Pure, render-agnostic assembled document data.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Document;

/**
 * Architecture Plan §10: "Assembly != rendering." An `Engine\DocumentAssembler\*`
 * produces one of these from `(Fulfillment, OrderSnapshot, items, packages,
 * Settings)` — fully unit-testable, no HTML. A `Documents\*Renderer`
 * consumes it. Line items and package summaries are plain assembled-array
 * shapes (the same "read-model projection" pattern
 * `Application\FulfillmentDetailView`'s timeline already uses), not
 * persisted entities — this model is never saved, only rendered.
 *
 * The barcode payload is a Code 128 payload string (the order number) from
 * the milestone the first document type ships (§10), so a slip is
 * scannable the day M6 lands, even though M2 prints it as plain text
 * (rendering it as an actual barcode image is deferred to M6).
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
	 * Store name, from settings/site identity.
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
	 * Assembles a document model.
	 *
	 * @param string                           $doc_type        Document type registry key.
	 * @param int                              $fulfillment_id  The fulfillment this document describes.
	 * @param string                           $order_number    Order number.
	 * @param string                           $customer_name   Customer display name.
	 * @param array<int, string>               $ship_to_lines   Ship-to address, as display lines.
	 * @param string                           $store_name      Store name.
	 * @param array<int, array<string, mixed>> $items     Line items.
	 * @param array<int, array<string, mixed>> $packages  Package summaries.
	 * @param string                           $barcode_payload Code 128 barcode payload.
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
		string $barcode_payload
	) {
		$this->doc_type        = $doc_type;
		$this->fulfillment_id  = $fulfillment_id;
		$this->order_number    = $order_number;
		$this->customer_name   = $customer_name;
		$this->ship_to_lines   = $ship_to_lines;
		$this->store_name      = $store_name;
		$this->items           = $items;
		$this->packages        = $packages;
		$this->barcode_payload = $barcode_payload;
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
}
