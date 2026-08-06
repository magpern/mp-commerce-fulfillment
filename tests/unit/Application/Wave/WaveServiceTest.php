<?php
/**
 * WaveService lifecycle unit tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Wave;

use DateTimeImmutable;
use MPCF\Application\AssignmentService;
use MPCF\Application\EventDispatcher;
use MPCF\Application\TransitionContextFactory;
use MPCF\Application\Wave\WaveService;
use MPCF\Application\WorkflowService;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Wave\WaveState;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Engine\GuardRegistry;
use MPCF\Engine\WorkflowEngine;
use MPCF\Settings;
use MPCF\Tests\Unit\Application\Doubles\FixedClock;
use MPCF\Tests\Unit\Application\Doubles\InMemoryEventRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentItemRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryPackageRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryShipmentRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryWaveRepository;
use PHPUnit\Framework\TestCase;

/**
 * Create / activate / abandon.
 */
final class WaveServiceTest extends TestCase {

	public function test_create_activate_and_abandon(): void {
		$now          = new DateTimeImmutable( '2026-08-06 12:00:00' );
		$fulfillments = new InMemoryFulfillmentRepository();
		$items        = new InMemoryFulfillmentItemRepository();
		$events       = new InMemoryEventRepository();
		$waves        = new InMemoryWaveRepository();
		$dispatcher   = new EventDispatcher();
		$clock        = new FixedClock( $now );
		$settings     = new Settings( array() );
		$shipments    = new InMemoryShipmentRepository();
		$packages     = new InMemoryPackageRepository();

		$f1 = $this->queued_fulfillment( 1, $now );
		$f2 = $this->queued_fulfillment( 2, $now );
		$fulfillments->insert( $f1 );
		$fulfillments->insert( $f2 );
		$items->insert_all( array( $this->item( 1, 1 ), $this->item( 2, 2 ) ) );

		$workflow = new WorkflowService(
			$fulfillments,
			$events,
			new WorkflowEngine( GuardRegistry::standard() ),
			$dispatcher,
			$clock,
			array( StandardWorkflow::NAME => StandardWorkflow::definition() ),
			new TransitionContextFactory( $items, $shipments, $packages, $settings )
		);

		$assignments = new AssignmentService( $fulfillments, $events, $dispatcher, $clock );
		$service     = new WaveService( $waves, $fulfillments, $items, $assignments, $workflow, $events, $dispatcher, $clock, $settings );
		$actor       = Actor::user( 9, 'Op' );

		$created = $service->create( 1, $actor, array( 1, 2 ), 'Test wave' );
		self::assertTrue( $created->is_success() );
		$wave_id = (int) $created->wave()->id();

		$activated = $service->activate( $wave_id, $actor, $created->wave()->version() );
		self::assertTrue( $activated->is_success(), (string) $activated->failure_message() );
		self::assertSame( WaveState::ACTIVE, $activated->wave()->state() );
		self::assertSame( 'picking', $fulfillments->find( 1 )->state() );

		$abandoned = $service->abandon( $wave_id, $actor, $activated->wave()->version() );
		self::assertTrue( $abandoned->is_success() );
		self::assertSame( WaveState::ABANDONED, $abandoned->wave()->state() );
		self::assertNull( $waves->find_open_for_fulfillment( 1 ) );
		self::assertSame( 'picking', $fulfillments->find( 1 )->state(), 'Abandon must not cancel fulfillments.' );
	}

	public function test_membership_exclusivity(): void {
		$now          = new DateTimeImmutable( '2026-08-06 12:00:00' );
		$fulfillments = new InMemoryFulfillmentRepository();
		$items        = new InMemoryFulfillmentItemRepository();
		$events       = new InMemoryEventRepository();
		$waves        = new InMemoryWaveRepository();
		$dispatcher   = new EventDispatcher();
		$clock        = new FixedClock( $now );
		$settings     = new Settings( array() );
		$shipments    = new InMemoryShipmentRepository();
		$packages     = new InMemoryPackageRepository();

		$fulfillments->insert( $this->queued_fulfillment( 1, $now ) );
		$items->insert_all( array( $this->item( 1, 1 ) ) );

		$workflow = new WorkflowService(
			$fulfillments,
			$events,
			new WorkflowEngine( GuardRegistry::standard() ),
			$dispatcher,
			$clock,
			array( StandardWorkflow::NAME => StandardWorkflow::definition() ),
			new TransitionContextFactory( $items, $shipments, $packages, $settings )
		);
		$service  = new WaveService(
			$waves,
			$fulfillments,
			$items,
			new AssignmentService( $fulfillments, $events, $dispatcher, $clock ),
			$workflow,
			$events,
			$dispatcher,
			$clock,
			$settings
		);
		$actor    = Actor::user( 1, 'Op' );

		$a = $service->create( 1, $actor, array( 1 ) );
		self::assertTrue( $a->is_success() );
		$b = $service->create( 1, $actor, array( 1 ) );
		self::assertFalse( $b->is_success() );
		self::assertSame( 'already_in_wave', $b->failure_code() );
	}

	private function queued_fulfillment( int $id, DateTimeImmutable $now ): Fulfillment {
		return Fulfillment::from_array(
			array(
				'id'                     => $id,
				'order_id'               => 1000 + $id,
				'order_source'           => 'woocommerce',
				'warehouse_id'           => 1,
				'workflow'               => StandardWorkflow::NAME,
				'state'                  => 'queued',
				'previous_state'         => null,
				'return_to_state'        => null,
				'exception_reason'       => null,
				'priority'               => 0,
				'assignee_type'          => null,
				'assignee_id'            => null,
				'version'                => 1,
				'order_number_snapshot'  => (string) $id,
				'customer_name_snapshot' => 'Cust',
				'item_count'             => 1,
				'created_at'             => $now,
				'state_entered_at'       => $now,
				'completed_at'           => null,
			)
		);
	}

	private function item( int $id, int $fid ): FulfillmentItem {
		return FulfillmentItem::from_array(
			array(
				'id'                => $id,
				'fulfillment_id'    => $fid,
				'order_item_id'     => $id,
				'product_id'        => 1,
				'variation_id'      => 0,
				'sku_snapshot'      => 'SKU-' . $id,
				'name_snapshot'     => 'Item',
				'qty_ordered'       => 1,
				'qty_picked'        => 0,
				'qty_packed'        => 0,
				'location_snapshot' => 'A1',
			)
		);
	}
}
