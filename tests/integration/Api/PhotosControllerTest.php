<?php
/**
 * Integration tests for package photo REST routes (M6-B).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Api;

use DateTimeImmutable;
use MPCF\Capabilities;
use MPCF\Domain\Media\PhotoKind;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Plugin;
use MPCF\Settings;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Tests\Integration\Woo\OrderFactoryTrait;
use ReflectionClass;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Real composition-root coverage for capture, stream, delete, and packing guard.
 */
final class PhotosControllerTest extends WP_UnitTestCase {

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

		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagejpeg' ) ) {
			$this->markTestSkipped( 'GD extension required for photo integration tests.' );
		}

		$this->clean_fulfillment_tables();
		Plugin::activate();
		$this->reboot_plugin();

		$this->fulfillments = new WpdbFulfillmentRepository();
		$this->items        = new WpdbFulfillmentItemRepository();
		$this->events       = new WpdbEventRepository();
	}

	protected function tearDown(): void {
		update_option( Settings::OPTION, Settings::sanitize( Settings::defaults() ) );
		parent::tearDown();
	}

	/**
	 * Resets the Plugin singleton and re-registers REST routes.
	 */
	private function reboot_plugin(): void {
		$reflection = new ReflectionClass( Plugin::class );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		remove_all_actions( 'rest_api_init' );
		remove_all_filters( 'rest_pre_serve_request' );

		Plugin::instance()->init();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Seeds a packing-state fulfillment with a weighed package.
	 *
	 * @return array{fulfillment_id:int,package_id:int,version:int}
	 */
	private function seed_packing_ready(): array {
		$order       = $this->create_paid_order( 1 );
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );
		$id          = (int) $fulfillment->id();

		foreach ( $this->items->find_for_fulfillment( $id ) as $item ) {
			$item->record_picked( $item->qty_ordered() );
			$item->record_packed( $item->qty_ordered() );
			$this->items->save( $item );
		}

		foreach ( array( 'picking', 'picked', 'packing' ) as $state ) {
			$fulfillment = $this->fulfillments->find( $id );
			$fulfillment->apply_transition( $state, null, new DateTimeImmutable() );
			$this->fulfillments->save( $fulfillment );
		}

		$create = $this->server->dispatch( new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$id}/shipments" ) );
		self::assertSame( 201, $create->get_status(), (string) wp_json_encode( $create->as_error() ) );

		$list = $this->server->dispatch( new WP_REST_Request( 'GET', "/mpcf/v1/fulfillments/{$id}/shipments" ) );
		self::assertSame( 200, $list->get_status() );
		$package_id = (int) $list->get_data()['shipments'][0]['packages'][0]['id'];

		$patch = new WP_REST_Request( 'PATCH', "/mpcf/v1/packages/{$package_id}" );
		$patch->set_body_params( array( 'weight_grams' => 500 ) );
		self::assertSame( 200, $this->server->dispatch( $patch )->get_status() );

		$fulfillment = $this->fulfillments->find( $id );

		return array(
			'fulfillment_id' => $id,
			'package_id'     => $package_id,
			'version'        => $fulfillment->version(),
		);
	}

	/**
	 * Creates a small JPEG temp file for upload fixtures.
	 */
	private function make_jpeg_temp(): string {
		$tmp = tempnam( sys_get_temp_dir(), 'mpcf-photo-' );
		self::assertNotFalse( $tmp );
		$img = imagecreatetruecolor( 32, 24 );
		imagefilledrectangle( $img, 0, 0, 31, 23, imagecolorallocate( $img, 20, 120, 40 ) );
		imagejpeg( $img, $tmp, 90 );
		imagedestroy( $img );

		return $tmp;
	}

	/**
	 * @param int    $fulfillment_id Fulfillment id.
	 * @param int    $package_id     Package id.
	 * @param int    $version        Expected version.
	 * @param string $kind           Photo kind.
	 * @param string $tmp            Temp JPEG path.
	 * @param string $mime           Declared MIME.
	 */
	private function dispatch_capture(
		int $fulfillment_id,
		int $package_id,
		int $version,
		string $kind,
		string $tmp,
		string $mime = 'image/jpeg'
	) {
		$request = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$fulfillment_id}/photos" );
		$request->set_body_params(
			array(
				'package_id' => $package_id,
				'kind'       => $kind,
				'version'    => $version,
			)
		);
		$request->set_file_params(
			array(
				'file' => array(
					'name'     => 'package.jpg',
					'type'     => $mime,
					'tmp_name' => $tmp,
					'error'    => UPLOAD_ERR_OK,
					'size'     => (int) filesize( $tmp ),
				),
			)
		);

		return $this->server->dispatch( $request );
	}

	public function test_upload_list_metadata_content_thumb_and_version_bump(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );
		$seed = $this->seed_packing_ready();
		$tmp  = $this->make_jpeg_temp();

		$response = $this->dispatch_capture(
			$seed['fulfillment_id'],
			$seed['package_id'],
			$seed['version'],
			PhotoKind::PACKAGE,
			$tmp
		);

		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->as_error() ) );
		$data = $response->get_data();
		self::assertSame( $seed['version'] + 1, $data['version'] );
		self::assertTrue( $data['photo_requirement_satisfied'] );
		self::assertArrayNotHasKey( 'file_path', $data['photo'] );
		self::assertArrayNotHasKey( 'thumb_path', $data['photo'] );
		self::assertSame( PhotoKind::PACKAGE, $data['photo']['kind'] );
		$photo_id = (int) $data['photo']['id'];

		$list = $this->server->dispatch( new WP_REST_Request( 'GET', "/mpcf/v1/fulfillments/{$seed['fulfillment_id']}/photos" ) );
		self::assertSame( 200, $list->get_status() );
		self::assertCount( 1, $list->get_data()['photos'] );

		$filtered = new WP_REST_Request( 'GET', "/mpcf/v1/fulfillments/{$seed['fulfillment_id']}/photos" );
		$filtered->set_query_params( array( 'kind' => PhotoKind::CONTENTS ) );
		self::assertCount( 0, $this->server->dispatch( $filtered )->get_data()['photos'] );

		$meta = $this->server->dispatch( new WP_REST_Request( 'GET', "/mpcf/v1/photos/{$photo_id}" ) );
		self::assertSame( 200, $meta->get_status() );
		self::assertSame( $photo_id, $meta->get_data()['photo']['id'] );

		$content = $this->server->dispatch( new WP_REST_Request( 'GET', "/mpcf/v1/photos/{$photo_id}/content" ) );
		self::assertSame( 200, $content->get_status() );
		self::assertNotEmpty( $content->get_data()['__raw_bytes'] );
		self::assertSame( 'image/jpeg', $content->get_data()['mime'] );

		$thumb = $this->server->dispatch( new WP_REST_Request( 'GET', "/mpcf/v1/photos/{$photo_id}/thumb" ) );
		self::assertSame( 200, $thumb->get_status() );
		self::assertNotEmpty( $thumb->get_data()['__raw_bytes'] );
		self::assertSame( 'image/jpeg', $thumb->get_data()['mime'] );

		$types = array_column( $this->events->timeline_for_fulfillment( $seed['fulfillment_id'] ), 'event_type' );
		self::assertContains( 'photo.captured', $types );
	}

	public function test_invalid_mime_and_package_mismatch_and_stale_version(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );
		$seed = $this->seed_packing_ready();
		$tmp  = $this->make_jpeg_temp();

		$bad = tempnam( sys_get_temp_dir(), 'mpcf-bad-' );
		file_put_contents( $bad, '%PDF-1.4 not an image' );
		$bad_upload = $this->dispatch_capture(
			$seed['fulfillment_id'],
			$seed['package_id'],
			$seed['version'],
			PhotoKind::PACKAGE,
			$bad,
			'application/pdf'
		);
		self::assertSame( 400, $bad_upload->get_status() );
		self::assertSame( 'mpcf_photo_invalid_upload', $bad_upload->as_error()->get_error_code() );

		$mismatch = $this->dispatch_capture(
			$seed['fulfillment_id'],
			999999,
			$seed['version'],
			PhotoKind::PACKAGE,
			$tmp
		);
		self::assertContains( $mismatch->get_status(), array( 400, 404 ) );

		$stale = $this->dispatch_capture(
			$seed['fulfillment_id'],
			$seed['package_id'],
			$seed['version'] - 1,
			PhotoKind::PACKAGE,
			$this->make_jpeg_temp()
		);
		self::assertSame( 409, $stale->get_status() );
		self::assertSame( 'mpcf_version_conflict', $stale->as_error()->get_error_code() );
	}

	public function test_operator_cannot_delete_lead_can_and_idempotent_repeat(): void {
		$lead = self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) );
		wp_set_current_user( $lead );
		$seed     = $this->seed_packing_ready();
		$captured = $this->dispatch_capture(
			$seed['fulfillment_id'],
			$seed['package_id'],
			$seed['version'],
			PhotoKind::PACKAGE,
			$this->make_jpeg_temp()
		);
		$photo_id = (int) $captured->get_data()['photo']['id'];
		$version  = (int) $captured->get_data()['version'];

		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );
		$forbidden = new WP_REST_Request( 'DELETE', "/mpcf/v1/photos/{$photo_id}" );
		$forbidden->set_body_params( array( 'version' => $version ) );
		$denied = $this->server->dispatch( $forbidden );
		self::assertSame( 403, $denied->get_status() );
		self::assertSame( 'mpcf_forbidden', $denied->as_error()->get_error_code() );

		wp_set_current_user( $lead );
		$delete = new WP_REST_Request( 'DELETE', "/mpcf/v1/photos/{$photo_id}" );
		$delete->set_body_params( array( 'version' => $version ) );
		$deleted = $this->server->dispatch( $delete );
		self::assertSame( 200, $deleted->get_status() );
		self::assertFalse( $deleted->get_data()['photo_requirement_satisfied'] );
		$after = (int) $deleted->get_data()['version'];

		$repeat = new WP_REST_Request( 'DELETE', "/mpcf/v1/photos/{$photo_id}" );
		$repeat->set_body_params( array( 'version' => $after ) );
		$again = $this->server->dispatch( $repeat );
		self::assertSame( 200, $again->get_status() );
		self::assertSame( $after, $again->get_data()['version'] );

		$list = $this->server->dispatch( new WP_REST_Request( 'GET', "/mpcf/v1/fulfillments/{$seed['fulfillment_id']}/photos" ) );
		self::assertCount( 0, $list->get_data()['photos'] );

		$meta = $this->server->dispatch( new WP_REST_Request( 'GET', "/mpcf/v1/photos/{$photo_id}" ) );
		self::assertSame( 404, $meta->get_status() );

		$types = array_column( $this->events->timeline_for_fulfillment( $seed['fulfillment_id'] ), 'event_type' );
		self::assertContains( 'photo.deleted', $types );
		self::assertSame( 1, count( array_filter( $types, static fn( $t ): bool => 'photo.deleted' === $t ) ) );
	}

	public function test_photos_required_guard_on_packing_to_packed(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		// Off: packing→packed allowed without photos.
		update_option( Settings::OPTION, Settings::sanitize( array_merge( Settings::defaults(), array( 'photos_required' => false ) ) ) );
		$this->reboot_plugin();
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$seed = $this->seed_packing_ready();
		$ok   = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$seed['fulfillment_id']}/transitions" );
		$ok->set_body_params(
			array(
				'target'  => 'packed',
				'version' => $seed['version'],
			)
		);
		self::assertSame( 200, $this->server->dispatch( $ok )->get_status() );

		// On + no photo: blocked.
		update_option( Settings::OPTION, Settings::sanitize( array_merge( Settings::defaults(), array( 'photos_required' => true ) ) ) );
		$this->reboot_plugin();
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_LEAD ) ) );

		$seed2   = $this->seed_packing_ready();
		$blocked = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$seed2['fulfillment_id']}/transitions" );
		$blocked->set_body_params(
			array(
				'target'  => 'packed',
				'version' => $seed2['version'],
			)
		);
		$rejected = $this->server->dispatch( $blocked );
		self::assertSame( 422, $rejected->get_status() );
		self::assertSame( 'photo_required', $rejected->as_error()->get_error_data()['guard'] );

		// Contents-only still blocks.
		$contents = $this->dispatch_capture(
			$seed2['fulfillment_id'],
			$seed2['package_id'],
			$seed2['version'],
			PhotoKind::CONTENTS,
			$this->make_jpeg_temp()
		);
		self::assertSame( 201, $contents->get_status() );
		$version_after_contents = (int) $contents->get_data()['version'];
		self::assertFalse( $contents->get_data()['photo_requirement_satisfied'] );

		$still = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$seed2['fulfillment_id']}/transitions" );
		$still->set_body_params(
			array(
				'target'  => 'packed',
				'version' => $version_after_contents,
			)
		);
		self::assertSame( 422, $this->server->dispatch( $still )->get_status() );

		// Package photo enables.
		$package = $this->dispatch_capture(
			$seed2['fulfillment_id'],
			$seed2['package_id'],
			$version_after_contents,
			PhotoKind::PACKAGE,
			$this->make_jpeg_temp()
		);
		self::assertSame( 201, $package->get_status() );
		self::assertTrue( $package->get_data()['photo_requirement_satisfied'] );
		$version_ready = (int) $package->get_data()['version'];

		$allowed = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$seed2['fulfillment_id']}/transitions" );
		$allowed->set_body_params(
			array(
				'target'  => 'packed',
				'version' => $version_ready,
			)
		);
		$packed = $this->server->dispatch( $allowed );
		self::assertSame( 200, $packed->get_status(), (string) wp_json_encode( $packed->as_error() ) );

		// New fulfillment: package photo then delete → blocks again.
		$seed3 = $this->seed_packing_ready();
		$cap   = $this->dispatch_capture(
			$seed3['fulfillment_id'],
			$seed3['package_id'],
			$seed3['version'],
			PhotoKind::PACKAGE,
			$this->make_jpeg_temp()
		);
		$pid   = (int) $cap->get_data()['photo']['id'];
		$ver   = (int) $cap->get_data()['version'];

		$del = new WP_REST_Request( 'DELETE', "/mpcf/v1/photos/{$pid}" );
		$del->set_body_params( array( 'version' => $ver ) );
		$after_del = $this->server->dispatch( $del );
		self::assertSame( 200, $after_del->get_status() );

		$block_again = new WP_REST_Request( 'POST', "/mpcf/v1/fulfillments/{$seed3['fulfillment_id']}/transitions" );
		$block_again->set_body_params(
			array(
				'target'  => 'packed',
				'version' => (int) $after_del->get_data()['version'],
			)
		);
		$rej = $this->server->dispatch( $block_again );
		self::assertSame( 422, $rej->get_status() );
		self::assertSame( 'photo_required', $rej->as_error()->get_error_data()['guard'] );
	}
}
