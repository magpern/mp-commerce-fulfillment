<?php
/**
 * Integration tests for the picking workflow via REST API — quantity
 * persistence, completion detection, transition enablement, and audit
 * coalescing for idempotent item updates.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Api;

use MPCF\Capabilities;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Plugin;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Tests\Integration\Woo\OrderFactoryTrait;
use ReflectionClass;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * End-to-end REST coverage for the checklist quantity path the workspace
 * client uses — complements browser tests that exercise the JS handlers.
 */
final class PickingWorkflowIntegrationTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;
	use OrderFactoryTrait;

	/**
	 * @var WpdbFulfillmentRepository
	 */
	private WpdbFulfillmentRepository $fulfillments;

	/**
	 * @var WpdbFulfillmentItemRepository
	 */
	private WpdbFulfillmentItemRepository $items;

	/**
	 * @var WpdbEventRepository
	 */
	private WpdbEventRepository $events;

	/**
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();
		Plugin::activate();

		$reflection = new ReflectionClass( Plugin::class );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		Plugin::instance()->init();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$this->fulfillments = new WpdbFulfillmentRepository();
		$this->items        = new WpdbFulfillmentItemRepository();
		$this->events       = new WpdbEventRepository();

		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );
	}

	/**
	 * @return array{0:int,1:int,2:int} Fulfillment id, item id, ordered qty.
	 */
	private function seed_picking_fulfillment(): array {
		$order       = $this->create_paid_order( 1 );
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );
		$item        = $this->items->find_for_fulfillment( $fulfillment->id() )[0];

		$request = new WP_REST_Request( 'POST', '/mpcf/v1/fulfillments/' . $fulfillment->id() . '/transitions' );
		$request->set_body_params(
			array(
				'target'  => 'picking',
				'version' => $fulfillment->version(),
			)
		);

		$response = $this->server->dispatch( $request );
		self::assertSame( 200, $response->get_status() );

		$fulfillment = $this->fulfillments->find( $fulfillment->id() );

		return array( $fulfillment->id(), (int) $item->id(), $item->qty_ordered() );
	}

	/**
	 * @param int               $fulfillment_id Fulfillment id.
	 * @param int               $version        Optimistic-lock version.
	 * @param array<int, mixed> $lines          Item lines.
	 * @return array<string, mixed>
	 */
	private function update_items( int $fulfillment_id, int $version, array $lines ): array {
		$request = new WP_REST_Request( 'PUT', "/mpcf/v1/fulfillments/{$fulfillment_id}/items" );
		$request->set_body_params(
			array(
				'version' => $version,
				'lines'   => $lines,
			)
		);

		$response = $this->server->dispatch( $request );
		self::assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->as_error() ) );

		return (array) $response->get_data();
	}

	public function test_increment_persists_qty_picked_and_advances_version(): void {
		list( $fulfillment_id, $item_id ) = $this->seed_picking_fulfillment();

		$data = $this->update_items(
			$fulfillment_id,
			1,
			array(
				array(
					'item_id'    => $item_id,
					'qty_picked' => 1,
				),
			)
		);

		self::assertSame( 2, $data['version'] );
		self::assertSame( 1, $data['items'][0]['qty_picked'] );
		self::assertSame( 1, $this->items->find_for_fulfillment( $fulfillment_id )[0]->qty_picked() );
	}

	public function test_decrement_persists_qty_picked(): void {
		list( $fulfillment_id, $item_id ) = $this->seed_picking_fulfillment();

		$data = $this->update_items(
			$fulfillment_id,
			1,
			array(
				array(
					'item_id'    => $item_id,
					'qty_picked' => 1,
				),
			)
		);

		$data = $this->update_items(
			$fulfillment_id,
			(int) $data['version'],
			array(
				array(
					'item_id'    => $item_id,
					'qty_picked' => 0,
				),
			)
		);

		self::assertSame( 0, $data['items'][0]['qty_picked'] );
		self::assertSame( 0, $this->items->find_for_fulfillment( $fulfillment_id )[0]->qty_picked() );
	}

	public function test_idempotent_resubmit_does_not_append_a_second_items_picked_event(): void {
		list( $fulfillment_id, $item_id ) = $this->seed_picking_fulfillment();

		$data = $this->update_items(
			$fulfillment_id,
			1,
			array(
				array(
					'item_id'    => $item_id,
					'qty_picked' => 1,
				),
			)
		);

		$this->update_items(
			$fulfillment_id,
			(int) $data['version'],
			array(
				array(
					'item_id'    => $item_id,
					'qty_picked' => 1,
				),
			)
		);

		$picked_events = array_values(
			array_filter(
				$this->events->timeline_for_fulfillment( $fulfillment_id ),
				static fn( array $event ): bool => 'items.picked' === $event['event_type']
			)
		);

		self::assertCount( 1, $picked_events );
	}

	public function test_picked_transition_is_approved_when_every_line_is_fully_picked(): void {
		list( $fulfillment_id, $item_id, $qty_ordered ) = $this->seed_picking_fulfillment();

		$data = $this->update_items(
			$fulfillment_id,
			1,
			array(
				array(
					'item_id'    => $item_id,
					'qty_picked' => $qty_ordered,
				),
			)
		);

		$picked = array_values(
			array_filter(
				$data['transitions'],
				static fn( array $transition ): bool => 'picked' === $transition['target']
			)
		);

		self::assertNotEmpty( $picked );
		self::assertTrue( $picked[0]['approved'] );
	}

	public function test_transition_to_picked_succeeds_after_quantities_are_saved(): void {
		list( $fulfillment_id, $item_id, $qty_ordered ) = $this->seed_picking_fulfillment();

		$data = $this->update_items(
			$fulfillment_id,
			1,
			array(
				array(
					'item_id'    => $item_id,
					'qty_picked' => $qty_ordered,
				),
			)
		);

		$request = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$fulfillment_id}/transitions" );
		$request->set_body_params(
			array(
				'target'  => 'picked',
				'version' => (int) $data['version'],
			)
		);

		$response = $this->server->dispatch( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'picked', $this->fulfillments->find( $fulfillment_id )->state() );
	}
}
