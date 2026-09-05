<?php
/**
 * Integration tests for the ADR-0008 kit-parent line skip in
 * `WooOrderSource::line_items()`, against real orders (HPOS on).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Woo;

use MPCF\Application\EventDispatcher;
use MPCF\Application\TransitionContextFactory;
use MPCF\Application\WorkflowService;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Engine\GuardRegistry;
use MPCF\Engine\WorkflowEngine;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\Database\WpdbPackageRepository;
use MPCF\Infrastructure\Database\WpdbShipmentRepository;
use MPCF\Infrastructure\SystemClock;
use MPCF\Settings;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Woo\WooOrderSource;
use WC_Order;
use WP_UnitTestCase;

/**
 * No third-party bundle plugin is installed anywhere in this suite — every
 * test here builds an order shaped exactly like that plugin's Architecture
 * B output using nothing but real, persisted WooCommerce order-item meta
 * (see {@see OrderFactoryTrait::add_kit_parent_line()}). That is precisely
 * ADR-0008's claim under test: the parent-skip guard depends on persisted
 * data only, never on the bundle plugin's own code being present.
 */
final class WooOrderSourceKitLineTest extends WP_UnitTestCase {

	use OrderFactoryTrait;
	use CleanFulfillmentTablesTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();
	}

	/**
	 * AC1 + AC4: one kit parent plus three components produces exactly
	 * three picking rows, none of them the parent, and the fulfillment's
	 * own item_count agrees — with no bundle-plugin code loaded at all
	 * (there is none in this test process), proving a historical kit order
	 * is handled correctly when that plugin is entirely unavailable.
	 */
	public function test_a_kit_with_three_components_produces_exactly_three_picking_rows_and_no_parent_row(): void {
		$order = $this->create_paid_kit_order( array( 'Component A', 'Component B', 'Component C' ) );

		$snapshot = ( new WooOrderSource() )->find( $order->get_id() );

		self::assertCount( 3, $snapshot->items(), 'Only the three real component lines are pickable — never the kit parent.' );

		$parent_item_id = null;
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( '' !== (string) $item->get_meta( '_ucb_kit', true ) ) {
				$parent_item_id = (int) $item_id;
			}
		}
		self::assertNotNull( $parent_item_id, 'Test setup sanity: the order must actually have a kit-parent line.' );

		$snapshot_item_ids = array_map(
			static fn( $line ) => $line->order_item_id(),
			$snapshot->items()
		);
		self::assertNotContains( $parent_item_id, $snapshot_item_ids );

		// Intake already ran synchronously off woocommerce_payment_complete
		// (IntakeHooks, wired for the whole integration process) — assert
		// what it actually persisted, not merely what the source returns.
		$fulfillment = ( new WpdbFulfillmentRepository() )->find_by_order_id( $order->get_id() );
		self::assertNotNull( $fulfillment );
		self::assertSame( 3, $fulfillment->item_count() );

		$rows = ( new WpdbFulfillmentItemRepository() )->find_for_fulfillment( $fulfillment->id() );
		self::assertCount( 3, $rows );
		foreach ( $rows as $row ) {
			self::assertNotSame( $parent_item_id, $row->order_item_id() );
		}
	}

	/**
	 * AC2: two kits in the same order sharing a component name keep two
	 * distinct component order items, each with its own order_item_id and
	 * its own quantity — never merged into one row.
	 */
	public function test_two_kits_sharing_a_component_keep_separate_order_item_ids_and_quantities(): void {
		$order = $this->create_paid_order_with_two_kits_sharing_a_component();

		$snapshot = ( new WooOrderSource() )->find( $order->get_id() );

		// 2 unique-to-a-kit components + 2 "Shared Component" lines = 3 rows total.
		self::assertCount( 3, $snapshot->items() );

		$shared = array_values(
			array_filter(
				$snapshot->items(),
				static fn( $line ) => 'Shared Component' === $line->name()
			)
		);

		self::assertCount( 2, $shared, 'Both kits\' "Shared Component" lines must survive as separate rows.' );
		self::assertNotSame(
			$shared[0]->order_item_id(),
			$shared[1]->order_item_id(),
			'Each kit\'s component line keeps its own order_item_id.'
		);

		$quantities = array( $shared[0]->quantity(), $shared[1]->quantity() );
		sort( $quantities );
		self::assertSame( array( 2, 5 ), $quantities, 'Each kit\'s own quantity for the shared component must be preserved, not merged.' );
	}

	/**
	 * AC3: an ordinary, non-kit order is completely unaffected by the
	 * guard — same row count and shape as before ADR-0008.
	 */
	public function test_an_ordinary_non_kit_order_is_unchanged(): void {
		$order = $this->create_paid_order( 3 );

		$snapshot = ( new WooOrderSource() )->find( $order->get_id() );

		self::assertCount( 1, $snapshot->items() );
		self::assertSame( 3, $snapshot->items()[0]->quantity() );
	}

	/**
	 * AC8: the predicate is narrow. A standalone line carrying
	 * `_ucb_component` but no `_ucb_kit` (e.g. the same product bought on
	 * its own, outside any kit) must still be ingested — proving the guard
	 * does not accidentally hide a real, independent customer selection.
	 */
	public function test_a_standalone_component_product_without_a_kit_parent_is_still_ingested(): void {
		$order   = new WC_Order();
		$item_id = $order->add_product( $this->create_product( 'Standalone Component' ), 1 );
		$item    = $order->get_item( $item_id );
		$item->add_meta_data( '_ucb_component', '1', true );
		$item->save_meta_data();

		$order->set_billing_first_name( 'Jane' );
		$order->set_billing_last_name( 'Doe' );
		$order->set_status( 'pending' );
		$order->calculate_totals();
		$order->save();
		$order->payment_complete();

		$snapshot = ( new WooOrderSource() )->find( $order->get_id() );

		self::assertCount( 1, $snapshot->items(), '_ucb_component alone must never hide a line — only _ucb_kit does.' );
		self::assertSame( 'Standalone Component', $snapshot->items()[0]->name() );
	}

	/**
	 * AC9: the guard fails closed on an unrecognized snapshot version — it
	 * skips on the *presence* of `_ucb_kit`, never on its `v` field, so a
	 * kit-parent line built by a future, currently-unknown snapshot shape
	 * is still excluded rather than accidentally becoming a picking row.
	 */
	public function test_a_parent_line_with_an_unknown_snapshot_version_is_still_skipped(): void {
		$order = $this->create_paid_kit_order( array( 'Only Component' ) );

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( '' !== (string) $item->get_meta( '_ucb_kit', true ) ) {
				$item->update_meta_data( '_ucb_kit', '{"v":99,"kit_id":0,"kit_sku":"KIT-X","kit_qty":1,"components":[]}' );
				$item->save_meta_data();
			}
		}

		$snapshot = ( new WooOrderSource() )->find( $order->get_id() );

		self::assertCount( 1, $snapshot->items() );
		self::assertSame( 'Only Component', $snapshot->items()[0]->name() );
	}

	/**
	 * AC10: once every real component row is fully picked, the standard
	 * `picking -> picked` transition succeeds — proving
	 * `AllItemsPickedGuard` never sees, and is never blocked by, the kit
	 * parent (which never became a row for it to see in the first place).
	 */
	public function test_all_items_picked_guard_allows_picking_to_picked_once_every_real_component_is_picked(): void {
		$order = $this->create_paid_kit_order( array( 'Component A', 'Component B', 'Component C' ) );

		$fulfillment_repository = new WpdbFulfillmentRepository();
		$item_repository        = new WpdbFulfillmentItemRepository();

		$fulfillment = $fulfillment_repository->find_by_order_id( $order->get_id() );
		self::assertNotNull( $fulfillment );

		$service = new WorkflowService(
			$fulfillment_repository,
			new WpdbEventRepository(),
			new WorkflowEngine( GuardRegistry::standard() ),
			new EventDispatcher(),
			new SystemClock(),
			array( StandardWorkflow::NAME => StandardWorkflow::definition() ),
			new TransitionContextFactory(
				$item_repository,
				new WpdbShipmentRepository(),
				new WpdbPackageRepository(),
				new Settings( array() )
			)
		);

		self::assertTrue( $service->transition( $fulfillment->id(), 'picking', Actor::system() )->is_success() );

		foreach ( $item_repository->find_for_fulfillment( $fulfillment->id() ) as $row ) {
			$row->record_picked( $row->qty_ordered() );
			$item_repository->save( $row );
		}

		$result = $service->transition( $fulfillment->id(), 'picked', Actor::system() );

		self::assertTrue( $result->is_success(), 'All three real component rows are fully picked; the kit parent was never a row to block on.' );

		$reloaded = $fulfillment_repository->find( $fulfillment->id() );
		self::assertSame( 'picked', $reloaded->state() );
	}
}
