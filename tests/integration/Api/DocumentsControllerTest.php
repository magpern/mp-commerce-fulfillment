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
		// Packing slip stage policy (M4-A) requires packing+ states.
		$fulfillment->apply_transition( 'packing', null, new \DateTimeImmutable() );
		$this->fulfillments->save( $fulfillment );

		return (int) $fulfillment->id();
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
		self::assertSame( 'packing_slip', $data['document_type'] );
		self::assertTrue( $data['stored'] );
		self::assertTrue( $data['file_available'] );
		self::assertNotSame( '', $data['template_version'] );
		self::assertSame( $fulfillment_id, $data['fulfillment']['id'] );
		self::assertNotEmpty( $data['transitions'] );
	}

	public function test_render_accepts_picking_list_doc_type(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$order       = $this->create_paid_order_with_shipping_address();
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );
		$fulfillment->apply_transition( 'picking', null, new \DateTimeImmutable() );
		$this->fulfillments->save( $fulfillment );
		$fulfillment_id = (int) $fulfillment->id();

		$request = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$fulfillment_id}/documents/render" );
		$request->set_body_params( array( 'doc_type' => 'picking_list' ) );

		$response = $this->server->dispatch( $request );

		self::assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( 'picking_list', $data['document_type'] );
		self::assertStringContainsString( 'Picking list', $data['html'] );
	}

	public function test_render_rejects_unknown_doc_type(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$fulfillment_id = $this->seed_fulfillment_with_shipping_address();
		$request        = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$fulfillment_id}/documents/render" );
		$request->set_body_params( array( 'doc_type' => 'invoice' ) );

		$response = $this->server->dispatch( $request );

		self::assertSame( 422, $response->get_status() );
	}

	public function test_render_rejects_picking_list_in_packing_stage(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$fulfillment_id = $this->seed_fulfillment_with_shipping_address();
		$request        = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$fulfillment_id}/documents/render" );
		$request->set_body_params( array( 'doc_type' => 'picking_list' ) );

		$response = $this->server->dispatch( $request );

		self::assertSame( 422, $response->get_status() );
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

	public function test_list_documents_returns_history_rows(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$fulfillment_id = $this->seed_fulfillment_with_shipping_address();
		$render         = $this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$fulfillment_id}/documents/render" ) );
		self::assertSame( 201, $render->get_status() );
		$document_id = (int) $render->get_data()['document_id'];

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/mpcf/v1/documents' ) );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertArrayHasKey( 'items', $data );
		self::assertGreaterThanOrEqual( 1, $data['total'] );
		$ids = array_column( $data['items'], 'id' );
		self::assertContains( $document_id, $ids );

		$filtered = new WP_REST_Request( 'GET', '/mpcf/v1/documents' );
		$filtered->set_query_params( array( 'doc_type' => 'packing_slip' ) );
		$filtered_response = $this->server->dispatch( $filtered );

		self::assertSame( 200, $filtered_response->get_status() );
		$filtered_data = $filtered_response->get_data();
		self::assertArrayHasKey( 'items', $filtered_data );
		foreach ( $filtered_data['items'] as $row ) {
			self::assertSame( 'packing_slip', $row['doc_type'] );
		}
		self::assertContains( $document_id, array_column( $filtered_data['items'], 'id' ) );
	}

	public function test_reprint_returns_exact_html_and_does_not_create_a_new_document(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$fulfillment_id = $this->seed_fulfillment_with_shipping_address();
		$render         = $this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$fulfillment_id}/documents/render" ) );
		$document_id    = (int) $render->get_data()['document_id'];
		$html           = (string) $render->get_data()['html'];

		$request  = new WP_REST_Request( 'POST', "/mpcf/v1/documents/{$document_id}/reprint" );
		$response = $this->server->dispatch( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( $html, $response->get_data()['html'] );
		self::assertSame( $document_id, (int) $response->get_data()['document_id'] );

		// Query args must be set on the request — embedding `?doc_type=` in the
		// route path is not how WP_REST_Request parses filters (real HTTP clients
		// still send `/documents?doc_type=…` and WordPress populates params).
		$list_request = new WP_REST_Request( 'GET', '/mpcf/v1/documents' );
		$list_request->set_query_params( array( 'doc_type' => 'packing_slip' ) );
		$list = $this->server->dispatch( $list_request );

		self::assertSame( 200, $list->get_status() );
		self::assertArrayHasKey( 'items', $list->get_data() );
		$ids = array_column( $list->get_data()['items'], 'id' );
		self::assertSame( 1, count( array_filter( $ids, static fn( $id ): bool => (int) $id === $document_id ) ) );
	}

	public function test_content_and_reprint_are_forbidden_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );
		$fulfillment_id = $this->seed_fulfillment_with_shipping_address();
		$render         = $this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$fulfillment_id}/documents/render" ) );
		$document_id    = (int) $render->get_data()['document_id'];

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$content = $this->server->dispatch( new WP_REST_Request( 'GET', "/mpcf/v1/documents/{$document_id}/content" ) );
		$reprint = $this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/documents/{$document_id}/reprint" ) );

		self::assertSame( 403, $content->get_status() );
		self::assertSame( 403, $reprint->get_status() );
	}
}
