<?php
/**
 * Integration tests for PUT /mpcf/v1/fulfillments/{id}/items, against the
 * real composition root and a real database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Api;

use MPCF\Capabilities;
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
 * Same real-composition-root dispatch discipline as
 * {@see FulfillmentsControllerTest}.
 */
final class ItemsControllerTest extends WP_UnitTestCase {

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
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- A WordPress core global, not a plugin symbol.
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$this->fulfillments = new WpdbFulfillmentRepository();
		$this->items        = new WpdbFulfillmentItemRepository();
	}

	/**
	 * @return array{0:int,1:int} Fulfillment id, its single item's id.
	 */
	private function seed_fulfillment(): array {
		$order       = $this->create_paid_order( 3 );
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );
		$item        = $this->items->find_for_fulfillment( $fulfillment->id() )[0];

		return array( $fulfillment->id(), $item->id() );
	}

	public function test_update_items_persists_absolute_quantities_and_returns_version_and_transitions(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		list( $fulfillment_id, $item_id ) = $this->seed_fulfillment();

		$request = new WP_REST_Request( 'PUT', "/mpcf/v1/fulfillments/{$fulfillment_id}/items" );
		$request->set_body_params(
			array(
				'version' => 1,
				'lines'   => array(
					array(
						'item_id'    => $item_id,
						'qty_picked' => 2,
					),
				),
			)
		);

		$response = $this->server->dispatch( $request );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( 2, $data['version'] );
		self::assertSame( 2, $data['items'][0]['qty_picked'] );
		self::assertNotEmpty( $data['transitions'] );

		$stored = $this->items->find_for_fulfillment( $fulfillment_id )[0];
		self::assertSame( 2, $stored->qty_picked() );
	}

	public function test_update_items_is_forbidden_for_a_role_without_process_fulfillments(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		list( $fulfillment_id, $item_id ) = $this->seed_fulfillment();

		$request = new WP_REST_Request( 'PUT', "/mpcf/v1/fulfillments/{$fulfillment_id}/items" );
		$request->set_body_params(
			array(
				'version' => 1,
				'lines'   => array( array( 'item_id' => $item_id, 'qty_picked' => 1 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			)
		);

		$response = $this->server->dispatch( $request );

		self::assertSame( 403, $response->get_status() );
	}

	public function test_update_items_returns_409_on_a_stale_version(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		list( $fulfillment_id, $item_id ) = $this->seed_fulfillment();

		$request = new WP_REST_Request( 'PUT', "/mpcf/v1/fulfillments/{$fulfillment_id}/items" );
		$request->set_body_params(
			array(
				'version' => 999,
				'lines'   => array( array( 'item_id' => $item_id, 'qty_picked' => 1 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			)
		);

		$response = $this->server->dispatch( $request );

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'mpcf_version_conflict', $response->as_error()->get_error_code() );
		self::assertSame( 0, $this->items->find_for_fulfillment( $fulfillment_id )[0]->qty_picked() );
	}

	public function test_update_items_returns_400_for_a_line_referencing_a_foreign_item(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		list( $fulfillment_id ) = $this->seed_fulfillment();

		$request = new WP_REST_Request( 'PUT', "/mpcf/v1/fulfillments/{$fulfillment_id}/items" );
		$request->set_body_params(
			array(
				'version' => 1,
				'lines'   => array( array( 'item_id' => 999999, 'qty_picked' => 1 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			)
		);

		$response = $this->server->dispatch( $request );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'mpcf_invalid_payload', $response->as_error()->get_error_code() );
	}

	public function test_update_items_requires_a_non_empty_lines_array(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		list( $fulfillment_id ) = $this->seed_fulfillment();

		$request = new WP_REST_Request( 'PUT', "/mpcf/v1/fulfillments/{$fulfillment_id}/items" );
		$request->set_body_params(
			array(
				'version' => 1,
				'lines'   => array(),
			)
		);

		$response = $this->server->dispatch( $request );

		self::assertSame( 400, $response->get_status() );
	}
}
