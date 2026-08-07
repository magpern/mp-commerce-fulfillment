<?php
/**
 * WaveScanService FIFO allocation unit tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Application\Wave;

use DateTimeImmutable;
use MPCF\Application\AssignmentService;
use MPCF\Application\EventDispatcher;
use MPCF\Application\PackingService;
use MPCF\Application\TransitionContextFactory;
use MPCF\Application\Wave\WaveScanService;
use MPCF\Application\Wave\WaveService;
use MPCF\Application\WorkflowService;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Domain\Scan\ScanResolver;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Engine\GuardRegistry;
use MPCF\Engine\WorkflowEngine;
use MPCF\Settings;
use MPCF\Tests\Unit\Application\Doubles\FixedClock;
use MPCF\Tests\Unit\Application\Doubles\InMemoryEventRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentItemRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryPackageRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryScanCorrectionStore;
use MPCF\Tests\Unit\Application\Doubles\InMemoryShipmentRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryWaveRepository;
use PHPUnit\Framework\TestCase;

/**
 * Shared SKU allocates FIFO by fulfillment created_at then item_id.
 */
final class WaveScanServiceTest extends TestCase {

	public function test_shared_sku_allocates_fifo(): void {
		$clock        = new FixedClock( new DateTimeImmutable( '2026-08-06 12:00:00' ) );
		$fulfillments = new InMemoryFulfillmentRepository();
		$items        = new InMemoryFulfillmentItemRepository();
		$events       = new InMemoryEventRepository();
		$waves        = new InMemoryWaveRepository();
		$dispatcher   = new EventDispatcher();
		$settings     = new Settings( array() );
		$shipments    = new InMemoryShipmentRepository();
		$packages     = new InMemoryPackageRepository();
		$corrections  = new InMemoryScanCorrectionStore();

		$f1 = $this->picking_fulfillment( 1, '2026-08-06 10:00:00' );
		$f2 = $this->picking_fulfillment( 2, '2026-08-06 09:00:00' );
		$fulfillments->insert( $f1 );
		$fulfillments->insert( $f2 );
		$items->insert_all(
			array(
				$this->item( 10, 1, 'SHARED' ),
				$this->item( 20, 2, 'SHARED' ),
			)
		);

		$workflow    = new WorkflowService(
			$fulfillments,
			$events,
			new WorkflowEngine( GuardRegistry::standard() ),
			$dispatcher,
			$clock,
			array( StandardWorkflow::NAME => StandardWorkflow::definition() ),
			new TransitionContextFactory( $items, $shipments, $packages, $settings )
		);
		$assignments = new AssignmentService( $fulfillments, $events, $dispatcher, $clock );
		$packing     = new PackingService( $fulfillments, $items, $events, $dispatcher, $clock );
		$wave_svc    = new WaveService( $waves, $fulfillments, $items, $assignments, $workflow, $events, $dispatcher, $clock, $settings );
		$scan        = new WaveScanService( $waves, $fulfillments, $items, $packing, $workflow, $wave_svc, new ScanResolver(), $corrections, $events, $dispatcher, $clock );
		$actor       = Actor::user( 5, 'Picker' );

		$created = $wave_svc->create( 1, $actor, array( 1, 2 ) );
		self::assertTrue( $created->is_success() );
		$activated = $wave_svc->activate( (int) $created->wave()->id(), $actor, $created->wave()->version() );
		self::assertTrue( $activated->is_success(), (string) $activated->failure_message() );

		$pick = $scan->scan_pick( (int) $activated->wave()->id(), $activated->wave()->version(), 'SHARED', $actor );
		self::assertTrue( $pick->is_success(), (string) $pick->failure_message() );
		self::assertSame( 2, $pick->data()['fulfillment_id'], 'Earlier created_at fulfillment wins FIFO.' );
		self::assertSame( 1, $items->find_for_fulfillment( 2 )[0]->qty_picked() );
		self::assertSame( 0, $items->find_for_fulfillment( 1 )[0]->qty_picked() );
	}

	private function picking_fulfillment( int $id, string $created ): Fulfillment {
		$now = new DateTimeImmutable( $created );

		return Fulfillment::from_array(
			array(
				'id'                     => $id,
				'order_id'               => 2000 + $id,
				'order_source'           => 'woocommerce',
				'warehouse_id'           => 1,
				'workflow'               => StandardWorkflow::NAME,
				'state'                  => 'picking',
				'previous_state'         => 'queued',
				'return_to_state'        => null,
				'exception_reason'       => null,
				'priority'               => 0,
				'assignee_type'          => 'user',
				'assignee_id'            => 5,
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

	private function item( int $id, int $fid, string $sku ): FulfillmentItem {
		return FulfillmentItem::from_array(
			array(
				'id'                => $id,
				'fulfillment_id'    => $fid,
				'order_item_id'     => $id,
				'product_id'        => 99,
				'variation_id'      => 0,
				'sku_snapshot'      => $sku,
				'name_snapshot'     => $sku,
				'qty_ordered'       => 1,
				'qty_picked'        => 0,
				'qty_packed'        => 0,
				'location_snapshot' => 'B1',
			)
		);
	}
}
