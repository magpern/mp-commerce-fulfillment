<?php
/**
 * Integration tests for GET /mpcf/v1/carriers, against the real
 * composition root and a real database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Api;

use MPCF\Capabilities;
use MPCF\Plugin;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use ReflectionClass;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Same real-composition-root dispatch discipline as
 * {@see FulfillmentsControllerTest}.
 */
final class CarriersControllerTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;

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
	}

	public function test_list_carriers_includes_the_bundled_set(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/mpcf/v1/carriers' ) );

		self::assertSame( 200, $response->get_status() );
		$ids = array_column( $response->get_data()['carriers'], 'id' );
		self::assertContains( 'postnord', $ids );
		self::assertContains( 'bring', $ids );
		self::assertContains( 'other', $ids );
	}

	public function test_list_carriers_exposes_additive_tracking_metadata(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/mpcf/v1/carriers' ) );

		self::assertSame( 200, $response->get_status() );
		$carriers = $response->get_data()['carriers'];
		self::assertNotEmpty( $carriers );

		$postnord = null;
		foreach ( $carriers as $carrier ) {
			if ( 'postnord' === $carrier['id'] ) {
				$postnord = $carrier;
				break;
			}
		}

		self::assertNotNull( $postnord );
		self::assertArrayHasKey( 'label', $postnord );
		self::assertArrayHasKey( 'tracking_url_template', $postnord );
		self::assertArrayHasKey( 'tracking_number_pattern', $postnord );
		self::assertArrayHasKey( 'phone_required', $postnord );
		self::assertStringContainsString( '{tracking}', (string) $postnord['tracking_url_template'] );
	}

	public function test_list_carriers_is_forbidden_for_a_role_without_view_queue(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/mpcf/v1/carriers' ) );

		self::assertSame( 403, $response->get_status() );
	}
}
