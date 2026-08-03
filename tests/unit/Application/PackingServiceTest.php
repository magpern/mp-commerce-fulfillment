<?php
/**
 * Tests for the batch picked/packed quantity service.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application;

use DateTimeImmutable;
use MPCF\Application\EventDispatcher;
use MPCF\Application\PackingService;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Tests\Unit\Application\Doubles\FixedClock;
use MPCF\Tests\Unit\Application\Doubles\InMemoryEventRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentItemRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the batch picked/packed quantity service.
 */
final class PackingServiceTest extends TestCase {

	/**
	 * @var InMemoryFulfillmentRepository
	 */
	private InMemoryFulfillmentRepository $fulfillments;

	/**
	 * @var InMemoryFulfillmentItemRepository
	 */
	private InMemoryFulfillmentItemRepository $items;

	/**
	 * @var InMemoryEventRepository
	 */
	private InMemoryEventRepository $events;

	/**
	 * @var PackingService
	 */
	private PackingService $service;

	/**
	 * @var int
	 */
	private int $fulfillment_id;

	/**
	 * @var int
	 */
	private int $item_a_id;

	/**
	 * @var int
	 */
	private int $item_b_id;

	protected function setUp(): void {
		$this->fulfillments = new InMemoryFulfillmentRepository();
		$this->items        = new InMemoryFulfillmentItemRepository();
		$this->events       = new InMemoryEventRepository();

		$this->service = new PackingService(
			$this->fulfillments,
			$this->items,
			$this->events,
			new EventDispatcher(),
			new FixedClock( new DateTimeImmutable( '2026-08-02 10:00:00' ) )
		);

		$this->fulfillment_id = $this->fulfillments->insert(
			Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'picking', '#1001', 'Jane Doe', 1, new DateTimeImmutable() )
		);

		$this->items->insert_all(
			array(
				FulfillmentItem::intake( $this->fulfillment_id, 501, 900, 0, 'SKU-1', 'Widget', 3 ),
				FulfillmentItem::intake( $this->fulfillment_id, 502, 901, 0, 'SKU-2', 'Gadget', 1 ),
			)
		);

		$stored          = $this->items->find_for_fulfillment( $this->fulfillment_id );
		$this->item_a_id = (int) $stored[0]->id();
		$this->item_b_id = (int) $stored[1]->id();
	}

	public function test_update_quantities_persists_absolute_qty_picked_and_advances_version(): void {
		$outcome = $this->service->update_quantities(
			$this->fulfillment_id,
			1,
			array(
				array(
					'item_id'    => $this->item_a_id,
					'qty_picked' => 2,
				),
			),
			Actor::system()
		);

		self::assertTrue( $outcome->is_success() );
		self::assertSame( 2, $outcome->version() );

		$item = $this->items->find_for_fulfillment( $this->fulfillment_id )[0];
		self::assertSame( 2, $item->qty_picked() );

		self::assertCount( 1, $this->events->timeline_for_fulfillment( $this->fulfillment_id ) );
	}

	public function test_a_batch_touching_multiple_lines_produces_exactly_one_itemized_event(): void {
		$outcome = $this->service->update_quantities(
			$this->fulfillment_id,
			1,
			array(
				array(
					'item_id'    => $this->item_a_id,
					'qty_picked' => 3,
				),
				array(
					'item_id'    => $this->item_b_id,
					'qty_picked' => 1,
				),
			),
			Actor::system()
		);

		self::assertTrue( $outcome->is_success() );

		$timeline = $this->events->timeline_for_fulfillment( $this->fulfillment_id );
		self::assertCount( 1, $timeline, 'One burst must coalesce into exactly one audit event.' );
		self::assertSame( 'items.picked', $timeline[0]['event_type'] );
		self::assertCount( 2, $timeline[0]['payload']['lines'] );
	}

	public function test_a_batch_setting_both_qty_picked_and_qty_packed_produces_two_events(): void {
		$outcome = $this->service->update_quantities(
			$this->fulfillment_id,
			1,
			array(
				array(
					'item_id'    => $this->item_a_id,
					'qty_picked' => 3,
					'qty_packed' => 2,
				),
			),
			Actor::system()
		);

		self::assertTrue( $outcome->is_success() );

		$timeline    = $this->events->timeline_for_fulfillment( $this->fulfillment_id );
		$event_types = array_map( static fn( array $event ): string => $event['event_type'], $timeline );

		self::assertSame( array( 'items.picked', 'items.packed' ), $event_types );
	}

	public function test_quantities_are_clamped_to_qty_ordered_even_when_the_caller_sends_more(): void {
		$outcome = $this->service->update_quantities(
			$this->fulfillment_id,
			1,
			array(
				array(
					'item_id'    => $this->item_a_id,
					'qty_picked' => 999,
				),
			),
			Actor::system()
		);

		self::assertTrue( $outcome->is_success() );
		self::assertSame( 3, $outcome->updated_items()[0]->qty_picked() );
	}

	public function test_version_mismatch_fails_and_writes_nothing(): void {
		$outcome = $this->service->update_quantities(
			$this->fulfillment_id,
			999,
			array(
				array(
					'item_id'    => $this->item_a_id,
					'qty_picked' => 2,
				),
			),
			Actor::system()
		);

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'version_conflict', $outcome->failure_code() );

		$item = $this->items->find_for_fulfillment( $this->fulfillment_id )[0];
		self::assertSame( 0, $item->qty_picked(), 'A version conflict must not leave a partial write behind.' );
		self::assertCount( 0, $this->events->timeline_for_fulfillment( $this->fulfillment_id ) );
	}

	public function test_a_line_referencing_a_foreign_item_id_fails_the_whole_batch(): void {
		$outcome = $this->service->update_quantities(
			$this->fulfillment_id,
			1,
			array(
				array(
					'item_id'    => $this->item_a_id,
					'qty_picked' => 2,
				),
				array(
					'item_id'    => 999999,
					'qty_picked' => 1,
				),
			),
			Actor::system()
		);

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'invalid_payload', $outcome->failure_code() );

		$item = $this->items->find_for_fulfillment( $this->fulfillment_id )[0];
		self::assertSame( 0, $item->qty_picked(), 'A rejected batch must not partially apply.' );
	}

	public function test_an_unknown_fulfillment_fails_with_not_found(): void {
		$outcome = $this->service->update_quantities(
			999999,
			1,
			array(
				array(
					'item_id'    => 1,
					'qty_picked' => 1,
				),
			),
			Actor::system()
		);

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'not_found', $outcome->failure_code() );
	}

	public function test_an_empty_batch_fails_with_invalid_payload(): void {
		$outcome = $this->service->update_quantities( $this->fulfillment_id, 1, array(), Actor::system() );

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'invalid_payload', $outcome->failure_code() );
	}

	public function test_a_no_op_batch_writes_nothing_and_appends_no_audit_event(): void {
		$this->service->update_quantities(
			$this->fulfillment_id,
			1,
			array(
				array(
					'item_id'    => $this->item_a_id,
					'qty_picked' => 1,
				),
			),
			Actor::system()
		);

		$outcome = $this->service->update_quantities(
			$this->fulfillment_id,
			2,
			array(
				array(
					'item_id'    => $this->item_a_id,
					'qty_picked' => 1,
				),
			),
			Actor::system()
		);

		self::assertTrue( $outcome->is_success() );
		self::assertSame( 2, $outcome->version(), 'An idempotent resubmit must not advance version.' );
		self::assertCount( 1, $this->events->timeline_for_fulfillment( $this->fulfillment_id ) );
	}

	public function test_complete_all_sets_every_line_to_its_ordered_quantity(): void {
		$outcome = $this->service->complete_all( $this->fulfillment_id, 1, 'qty_picked', Actor::system() );

		self::assertTrue( $outcome->is_success() );

		$items = $this->items->find_for_fulfillment( $this->fulfillment_id );
		self::assertSame( 3, $items[0]->qty_picked() );
		self::assertSame( 1, $items[1]->qty_picked() );
	}

	public function test_every_batch_produces_a_valid_hash_chained_event(): void {
		$this->service->update_quantities(
			$this->fulfillment_id,
			1,
			array(
				array(
					'item_id'    => $this->item_a_id,
					'qty_picked' => 1,
				),
			),
			Actor::system()
		);
		$this->service->update_quantities(
			$this->fulfillment_id,
			2,
			array(
				array(
					'item_id'    => $this->item_a_id,
					'qty_picked' => 2,
				),
			),
			Actor::system()
		);

		$timeline      = $this->events->timeline_for_fulfillment( $this->fulfillment_id );
		$previous_hash = null;

		foreach ( $timeline as $event ) {
			self::assertSame( $previous_hash, $event['prev_hash'] );
			$previous_hash = $event['hash'];
		}
	}
}
