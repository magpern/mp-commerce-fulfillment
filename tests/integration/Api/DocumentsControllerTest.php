<?php
/**
 * Integration tests for POST /mpcf/v1/fulfillments/{id}/documents/render,
 * against the real composition root and a real database.
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
final class DocumentsControllerTest extends WP_UnitTestCase {

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

	private function seed_fulfillment_with_shipping_address(): int {
		$order       = $this->create_paid_order_with_shipping_address();
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );

		return $fulfillment->id();
	}

	public function test_render_produces_html_and_records_a_document(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$fulfillment_id = $this->seed_fulfillment_with_shipping_address();

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$fulfillment_id}/documents/render" ) );

		self::assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		self::assertStringContainsString( 'Anna Andersson', $data['html'] );
		self::assertStringContainsString( '<style>', $data['html'] );
		self::assertIsInt( $data['document_id'] );
		self::assertSame( $fulfillment_id, $data['fulfillment']['id'] );
		self::assertNotEmpty( $data['transitions'] );
	}

	public function test_render_is_forbidden_for_a_role_without_render_documents(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$fulfillment_id = $this->seed_fulfillment_with_shipping_address();

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$fulfillment_id}/documents/render" ) );

		self::assertSame( 403, $response->get_status() );
	}

	public function test_render_404s_for_an_unknown_fulfillment(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/mpcf/v1/fulfillments/999999/documents/render' ) );

		self::assertSame( 404, $response->get_status() );
	}
}
