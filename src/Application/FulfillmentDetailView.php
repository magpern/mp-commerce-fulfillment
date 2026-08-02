<?php
/**
 * Everything the Fulfillment Detail screen needs for one fulfillment.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Note;

/**
 * Plain aggregate DTO assembled by {@see FulfillmentDetailService} — Admin
 * never assembles this itself from individual repositories (invariant I11).
 */
final class FulfillmentDetailView {

	/**
	 * The fulfillment itself.
	 *
	 * @var Fulfillment
	 */
	private Fulfillment $fulfillment;

	/**
	 * Its line items.
	 *
	 * @var list<FulfillmentItem>
	 */
	private array $items;

	/**
	 * Its full audit chain, oldest first.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $timeline;

	/**
	 * Its notes, pinned first.
	 *
	 * @var list<Note>
	 */
	private array $notes;

	/**
	 * Builds the view.
	 *
	 * @param Fulfillment                      $fulfillment The fulfillment itself.
	 * @param array<int, FulfillmentItem>      $items       Its line items.
	 * @param array<int, array<string, mixed>> $timeline    Its full audit chain, oldest first.
	 * @param array<int, Note>                 $notes       Its notes, pinned first.
	 */
	public function __construct( Fulfillment $fulfillment, array $items, array $timeline, array $notes ) {
		$this->fulfillment = $fulfillment;
		$this->items       = $items;
		$this->timeline    = $timeline;
		$this->notes       = $notes;
	}

	/**
	 * The fulfillment itself.
	 */
	public function fulfillment(): Fulfillment {
		return $this->fulfillment;
	}

	/**
	 * Its line items.
	 *
	 * @return list<FulfillmentItem>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Its full audit chain, oldest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function timeline(): array {
		return $this->timeline;
	}

	/**
	 * Its notes, pinned first.
	 *
	 * @return list<Note>
	 */
	public function notes(): array {
		return $this->notes;
	}
}
