<?php
/**
 * Integration tests for the Dashboard's read-side queries, against a real
 * database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Admin;

use DateTimeImmutable;
use MPCF\Application\DashboardService;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\SystemClock;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use WP_UnitTestCase;

/**
 * Proves the Dashboard's next-actions band and stat cards read real,
 * correctly-filtered rows — an operational "what needs attention now" view,
 * never an analytics query.
 */
final class DashboardServiceTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;

	/**
	 * @var WpdbFulfillmentRepository
	 */
	private WpdbFulfillmentRepository $fulfillments;

	/**
	 * @var WpdbEventRepository
	 */
	private WpdbEventRepository $events;

	/**
	 * @var DashboardService
	 */
	private DashboardService $dashboard;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();

		$this->fulfillments = new WpdbFulfillmentRepository();
		$this->events       = new WpdbEventRepository();
		$this->dashboard    = new DashboardService( $this->fulfillments, $this->events, new SystemClock() );
	}

	private function seed( int $order_id, string $state ): int {
		$fulfillment = Fulfillment::intake( $order_id, 'woocommerce', 1, 'standard', 'queued', '#' . $order_id, 'Jane Doe', 1, new DateTimeImmutable() );
		$id          = $this->fulfillments->insert( $fulfillment );

		if ( 'queued' !== $state ) {
			$stored = $this->fulfillments->find( $id );
			$stored->apply_transition( $state, null, new DateTimeImmutable() );
			$this->fulfillments->save( $stored );
		}

		return $id;
	}

	public function test_needs_attention_lists_only_exception_states_oldest_first(): void {
		$problem = $this->seed( 1001, 'problem' );
		$this->seed( 1002, 'queued' );

		$definition = StandardWorkflow::definition();
		$ids        = array_map( static fn( Fulfillment $f ): int => $f->id(), $this->dashboard->needs_attention( $definition ) );

		self::assertSame( array( $problem ), $ids );
	}

	public function test_oldest_open_excludes_terminal_states(): void {
		$open      = $this->seed( 2001, 'queued' );
		$completed = $this->seed( 2002, 'queued' );

		$stored = $this->fulfillments->find( $completed );
		$stored->apply_transition( 'completed', null, new DateTimeImmutable() );
		$this->fulfillments->save( $stored );

		$definition = StandardWorkflow::definition();
		$ids        = array_map( static fn( Fulfillment $f ): int => $f->id(), $this->dashboard->oldest_open( $definition ) );

		self::assertContains( $open, $ids );
		self::assertNotContains( $completed, $ids );
	}

	public function test_unassigned_excludes_assigned_open_fulfillments(): void {
		$assigned   = $this->seed( 3001, 'queued' );
		$unassigned = $this->seed( 3002, 'queued' );

		$fulfillment = $this->fulfillments->find( $assigned );
		$fulfillment->assign( 'user', 7 );
		$this->fulfillments->save( $fulfillment );

		$definition = StandardWorkflow::definition();
		$ids        = array_map( static fn( Fulfillment $f ): int => $f->id(), $this->dashboard->unassigned( $definition ) );

		self::assertContains( $unassigned, $ids );
		self::assertNotContains( $assigned, $ids );
	}

	public function test_open_and_exception_counts(): void {
		$this->seed( 4001, 'queued' );
		$this->seed( 4002, 'problem' );
		$completed = $this->seed( 4003, 'queued' );

		$stored = $this->fulfillments->find( $completed );
		$stored->apply_transition( 'completed', null, new DateTimeImmutable() );
		$this->fulfillments->save( $stored );

		$definition = StandardWorkflow::definition();

		self::assertSame( 2, $this->dashboard->open_count( $definition ) );
		self::assertSame( 1, $this->dashboard->exception_count( $definition ) );
	}

	public function test_packed_and_shipped_today_count_real_audit_events(): void {
		$id   = $this->seed( 5001, 'queued' );
		$item = FulfillmentItem::intake( $id, 501, 900, 0, 'SKU-1', 'Widget', 1 );
		( new WpdbFulfillmentItemRepository() )->insert_all( array( $item ) );

		$now = new DateTimeImmutable();

		$event = \MPCF\Domain\Event\DomainEvent::for_fulfillment(
			$id,
			'fulfillment.state_changed',
			Actor::system(),
			$now,
			array(
				'from' => 'packing',
				'to'   => 'packed',
			)
		);
		$this->events->append( $event, null );

		self::assertSame( 1, $this->dashboard->packed_today() );
		self::assertSame( 0, $this->dashboard->shipped_today() );
	}
}
