<?php
/**
 * Tests for idempotent order-to-fulfillment intake.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application;

use DateTimeImmutable;
use MPCF\Application\EventDispatcher;
use MPCF\Application\IntakeService;
use MPCF\Domain\OrderLineSnapshot;
use MPCF\Domain\OrderSnapshot;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Tests\Unit\Application\Doubles\FixedClock;
use MPCF\Tests\Unit\Application\Doubles\InMemoryEventRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentItemRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryOrderSource;
use PHPUnit\Framework\TestCase;

/**
 * Tests for idempotent order-to-fulfillment intake.
 */
final class IntakeServiceTest extends TestCase {

	/**
	 * @var InMemoryOrderSource
	 */
	private InMemoryOrderSource $orders;

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
	 * @var IntakeService
	 */
	private IntakeService $service;

	protected function setUp(): void {
		$this->orders       = new InMemoryOrderSource();
		$this->fulfillments = new InMemoryFulfillmentRepository();
		$this->items        = new InMemoryFulfillmentItemRepository();
		$this->events       = new InMemoryEventRepository();

		$this->service = new IntakeService(
			$this->orders,
			$this->fulfillments,
			$this->items,
			$this->events,
			new EventDispatcher(),
			new FixedClock( new DateTimeImmutable( '2026-08-02 10:00:00' ) ),
			StandardWorkflow::definition()
		);
	}

	private function seed_order( int $order_id = 1001 ): void {
		$this->orders->seed(
			OrderSnapshot::create(
				$order_id,
				'woocommerce',
				'#' . $order_id,
				'Jane Doe',
				'processing',
				array(
					OrderLineSnapshot::create( 501, 900, 0, 'SKU-1', 'Widget', 3 ),
					OrderLineSnapshot::create( 502, 901, 5, 'SKU-2', 'Gadget', 1 ),
				)
			)
		);
	}

	public function test_intake_creates_a_queued_fulfillment_with_its_line_items(): void {
		$this->seed_order();

		$outcome = $this->service->intake( 1001 );

		self::assertTrue( $outcome->is_success() );
		self::assertTrue( $outcome->was_created() );

		$fulfillment = $outcome->fulfillment();

		self::assertSame( 1001, $fulfillment->order_id() );
		self::assertSame( 'woocommerce', $fulfillment->order_source() );
		self::assertSame( StandardWorkflow::NAME, $fulfillment->workflow() );
		self::assertSame( 'queued', $fulfillment->state() );
		self::assertSame( '#1001', $fulfillment->order_number_snapshot() );
		self::assertSame( 'Jane Doe', $fulfillment->customer_name_snapshot() );
		self::assertSame( 2, $fulfillment->item_count() );
		self::assertNotNull( $fulfillment->id() );

		$items = $this->items->find_for_fulfillment( $fulfillment->id() );

		self::assertCount( 2, $items );
		self::assertSame( 'SKU-1', $items[0]->sku_snapshot() );
		self::assertSame( 3, $items[0]->qty_ordered() );
		self::assertSame( 5, $items[1]->variation_id() );
	}

	public function test_intake_appends_exactly_one_minimal_fulfillment_created_event(): void {
		$this->seed_order();

		$fulfillment = $this->service->intake( 1001 )->fulfillment();
		$timeline    = $this->events->timeline_for_fulfillment( $fulfillment->id() );

		self::assertCount( 1, $timeline );
		self::assertSame( 'fulfillment.created', $timeline[0]['event_type'] );
		self::assertSame( array( 'order_id' => 1001 ), $timeline[0]['payload'], 'The intake event payload must contain only the order reference, nothing else.' );
	}

	public function test_intake_is_a_no_op_the_second_time_for_the_same_order(): void {
		$this->seed_order();

		$first  = $this->service->intake( 1001 );
		$second = $this->service->intake( 1001 );

		self::assertTrue( $first->was_created() );
		self::assertTrue( $second->is_success() );
		self::assertFalse( $second->was_created(), 'A duplicate intake call must not create a second fulfillment.' );
		self::assertSame( $first->fulfillment()->id(), $second->fulfillment()->id() );

		self::assertCount( 1, $this->events->all(), 'A duplicate intake call must not append a second audit event.' );
	}

	public function test_intake_falls_back_to_the_existing_row_when_the_insert_loses_the_uniqueness_race(): void {
		$this->seed_order();

		// Simulates a second, concurrent intake attempt (a duplicate payment
		// notification, an Action Scheduler retry) that reaches insert()
		// after another call already won — the up-front find_by_order_id()
		// check cannot see this by construction, so the fallback is what
		// actually has to hold here.
		$racing_fulfillments = new class( $this->fulfillments ) implements \MPCF\Domain\Repository\FulfillmentRepository {
			/**
			 * @var InMemoryFulfillmentRepository
			 */
			private InMemoryFulfillmentRepository $real;

			public function __construct( InMemoryFulfillmentRepository $real ) {
				$this->real = $real;
			}

			public function find( int $id ): ?\MPCF\Domain\Fulfillment {
				return $this->real->find( $id );
			}

			public function find_by_order_id( int $order_id ): ?\MPCF\Domain\Fulfillment {
				return $this->real->find_by_order_id( $order_id );
			}

			public function find_all_by_order_id( int $order_id ): array {
				return $this->real->find_all_by_order_id( $order_id );
			}

			public function insert( \MPCF\Domain\Fulfillment $fulfillment ): ?int {
				$this->real->insert( $fulfillment );

				return null; // Always reports "lost the race", regardless of what actually happened.
			}

			public function save( \MPCF\Domain\Fulfillment $fulfillment ): bool {
				return $this->real->save( $fulfillment );
			}
		};

		$service = new IntakeService(
			$this->orders,
			$racing_fulfillments,
			$this->items,
			$this->events,
			new EventDispatcher(),
			new FixedClock( new DateTimeImmutable( '2026-08-02 10:00:00' ) ),
			StandardWorkflow::definition()
		);

		$outcome = $service->intake( 1001 );

		self::assertTrue( $outcome->is_success() );
		self::assertFalse( $outcome->was_created() );
		self::assertSame( 1001, $outcome->fulfillment()->order_id() );
	}

	public function test_intake_fails_when_the_order_cannot_be_found(): void {
		$outcome = $this->service->intake( 999999 );

		self::assertFalse( $outcome->is_success() );
		self::assertSame( 'order_not_found', $outcome->failure_code() );
	}
}
