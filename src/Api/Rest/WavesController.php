<?php
/**
 * REST controller for wave lifecycle and wave scan.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Api\Rest;

use MPCF\Application\DocumentService;
use MPCF\Application\Wave\WaveOutcome;
use MPCF\Application\Wave\WaveScanService;
use MPCF\Application\Wave\WaveService;
use MPCF\Capabilities;
use MPCF\Domain\Wave\Wave;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Architecture Plan Part X.6 — `/mpcf/v1/waves…`.
 */
final class WavesController extends AbstractRestController {

	/**
	 * Wave lifecycle service.
	 *
	 * @var WaveService
	 */
	private WaveService $waves;

	/**
	 * Wave scan service.
	 *
	 * @var WaveScanService
	 */
	private WaveScanService $scans;

	/**
	 * Document render orchestrator.
	 *
	 * @var DocumentService
	 */
	private DocumentService $documents;

	/**
	 * Builds the controller.
	 *
	 * @param WaveService     $waves     Wave lifecycle.
	 * @param WaveScanService $scans     Wave scan.
	 * @param DocumentService $documents Document renders.
	 */
	public function __construct( WaveService $waves, WaveScanService $scans, DocumentService $documents ) {
		$this->waves     = $waves;
		$this->scans     = $scans;
		$this->documents = $documents;
	}

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/waves',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_waves' ),
					'permission_callback' => $this->require_capability( Capabilities::PROCESS_FULFILLMENTS ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_wave' ),
					'permission_callback' => $this->require_capability( Capabilities::PROCESS_FULFILLMENTS ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/waves/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_wave' ),
					'permission_callback' => $this->require_capability( Capabilities::PROCESS_FULFILLMENTS ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
						),
					),
				),
			)
		);

		$this->register_member_routes();
		$this->register_lifecycle_routes();
		$this->register_walk_scan_document_routes();
	}

	/**
	 * Member add/remove routes.
	 */
	private function register_member_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/waves/(?P<id>\d+)/members',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_members' ),
					'permission_callback' => $this->require_capability( Capabilities::PROCESS_FULFILLMENTS ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/waves/(?P<id>\d+)/members/(?P<fulfillment_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_member' ),
					'permission_callback' => $this->require_capability( Capabilities::PROCESS_FULFILLMENTS ),
				),
			)
		);
	}

	/**
	 * Lifecycle mutation routes.
	 */
	private function register_lifecycle_routes(): void {
		foreach ( array( 'activate', 'pause', 'resume', 'complete', 'abandon' ) as $action ) {
			register_rest_route(
				self::NAMESPACE_V1,
				'/waves/(?P<id>\d+)/' . $action,
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'lifecycle_' . $action ),
						'permission_callback' => $this->require_capability( Capabilities::PROCESS_FULFILLMENTS ),
					),
				)
			);
		}
	}

	/**
	 * Walk / scan / document routes.
	 */
	private function register_walk_scan_document_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/waves/(?P<id>\d+)/walk',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_walk' ),
					'permission_callback' => $this->require_capability( Capabilities::PROCESS_FULFILLMENTS ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/waves/(?P<id>\d+)/scan',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_scan' ),
					'permission_callback' => $this->require_capability( Capabilities::PROCESS_FULFILLMENTS ),
					'args'                => array(
						'action'  => array(
							'type'              => 'string',
							'required'          => true,
							'enum'              => array( 'resolve', 'pick', 'undo' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'payload' => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => static function ( $value ): string {
								return is_string( $value ) ? trim( $value ) : '';
							},
						),
						'version' => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/waves/(?P<id>\d+)/documents',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'render_document' ),
					'permission_callback' => $this->require_capability( Capabilities::RENDER_DOCUMENTS ),
				),
			)
		);
	}

	/**
	 * GET /waves
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_waves( WP_REST_Request $request ) {
		$mine_only    = (bool) $request->get_param( 'mine' );
		$warehouse    = $request->get_param( 'warehouse_id' );
		$warehouse_id = null !== $warehouse && '' !== $warehouse ? (int) $warehouse : null;
		$actor        = self::current_actor();
		$user_id      = (int) $actor->id();
		$waves        = $this->waves->list_open( $mine_only ? $user_id : null, $warehouse_id );

		return $this->respond( array( 'waves' => $waves ) );
	}

	/**
	 * POST /waves
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_wave( WP_REST_Request $request ) {
		$warehouse_raw = $request->get_param( 'warehouse_id' );
		$warehouse_id  = ( null !== $warehouse_raw && '' !== $warehouse_raw ) ? (int) $warehouse_raw : 1;
		$title_raw     = $request->get_param( 'title' );
		$title         = is_string( $title_raw ) ? $title_raw : '';
		$ids_raw       = $request->get_param( 'fulfillment_ids' );
		$ids           = is_array( $ids_raw ) ? array_map( 'intval', $ids_raw ) : array();
		$outcome       = $this->waves->create( $warehouse_id, self::current_actor(), $ids, $title );

		return $this->outcome_response( $outcome, 201 );
	}

	/**
	 * GET /waves/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_wave( WP_REST_Request $request ) {
		$payload = $this->waves->get_with_progress( (int) $request->get_param( 'id' ) );

		if ( null === $payload ) {
			return self::failure_error( 'not_found', 'Wave not found.' );
		}

		return $this->respond( $this->wave_body( $payload['wave'], $payload['progress'], $payload['walk'] ) );
	}

	/**
	 * POST /waves/{id}/members
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function add_members( WP_REST_Request $request ) {
		$ids_raw = $request->get_param( 'fulfillment_ids' );
		$ids     = is_array( $ids_raw ) ? array_map( 'intval', $ids_raw ) : array();
		$version = $request->get_param( 'version' );
		$outcome = $this->waves->add_members(
			(int) $request->get_param( 'id' ),
			$ids,
			self::current_actor(),
			null !== $version && '' !== $version ? (int) $version : null
		);

		return $this->outcome_response( $outcome );
	}

	/**
	 * DELETE /waves/{id}/members/{fulfillment_id}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function remove_member( WP_REST_Request $request ) {
		$version = $request->get_param( 'version' );
		$outcome = $this->waves->remove_member(
			(int) $request->get_param( 'id' ),
			(int) $request->get_param( 'fulfillment_id' ),
			self::current_actor(),
			null !== $version && '' !== $version ? (int) $version : null
		);

		return $this->outcome_response( $outcome );
	}

	/**
	 * POST activate
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function lifecycle_activate( WP_REST_Request $request ) {
		return $this->lifecycle( $request, 'activate' );
	}

	/**
	 * POST pause
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function lifecycle_pause( WP_REST_Request $request ) {
		return $this->lifecycle( $request, 'pause' );
	}

	/**
	 * POST resume
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function lifecycle_resume( WP_REST_Request $request ) {
		return $this->lifecycle( $request, 'resume' );
	}

	/**
	 * POST complete
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function lifecycle_complete( WP_REST_Request $request ) {
		$version = $request->get_param( 'version' );
		$force   = (bool) $request->get_param( 'force' );
		$outcome = $this->waves->complete(
			(int) $request->get_param( 'id' ),
			self::current_actor(),
			null !== $version && '' !== $version ? (int) $version : null,
			$force
		);

		return $this->outcome_response( $outcome );
	}

	/**
	 * POST abandon
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function lifecycle_abandon( WP_REST_Request $request ) {
		return $this->lifecycle( $request, 'abandon' );
	}

	/**
	 * GET walk
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_walk( WP_REST_Request $request ) {
		$payload = $this->waves->get_with_progress( (int) $request->get_param( 'id' ) );

		if ( null === $payload ) {
			return self::failure_error( 'not_found', 'Wave not found.' );
		}

		return $this->respond(
			array(
				'walk'     => $payload['walk'],
				'progress' => $payload['progress'],
			)
		);
	}

	/**
	 * POST scan
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function handle_scan( WP_REST_Request $request ) {
		$wave_id = (int) $request->get_param( 'id' );
		$action  = (string) $request->get_param( 'action' );
		$payload = (string) $request->get_param( 'payload' );
		$version = $request->get_param( 'version' );
		$actor   = self::current_actor();

		if ( in_array( $action, array( 'pick', 'undo' ), true ) && ( null === $version || '' === $version ) ) {
			return self::failure_error( 'invalid_payload', 'version is required for mutating wave scan actions.' );
		}

		$expected = (int) $version;

		switch ( $action ) {
			case 'resolve':
				$outcome = $this->scans->resolve( $wave_id, $payload, $actor );
				break;
			case 'pick':
				$f_versions = $request->get_param( 'fulfillment_versions' );
				$map        = is_array( $f_versions ) ? array_map( 'intval', $f_versions ) : array();
				$outcome    = $this->scans->scan_pick( $wave_id, $expected, $payload, $actor, $map );
				break;
			case 'undo':
				$outcome = $this->scans->undo( $wave_id, $expected, $actor );
				break;
			default:
				return self::failure_error( 'invalid_payload', 'Unknown scan action.' );
		}

		return $this->outcome_response( $outcome );
	}

	/**
	 * POST documents — render wave picking list HTML.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function render_document( WP_REST_Request $request ) {
		$payload = $this->waves->get_with_progress( (int) $request->get_param( 'id' ) );

		if ( null === $payload ) {
			return self::failure_error( 'not_found', 'Wave not found.' );
		}

		$wave = $payload['wave'];
		assert( $wave instanceof Wave );
		$outcome = $this->documents->render_wave_picking_list( $wave, $payload['walk'], self::current_actor() );

		if ( ! $outcome->is_success() ) {
			return self::failure_error( (string) $outcome->failure_code(), (string) $outcome->failure_message() );
		}

		return $this->respond(
			array(
				'doc_type' => 'wave_picking_list',
				'html'     => $outcome->html(),
				'wave_id'  => (int) $wave->id(),
			)
		);
	}

	/**
	 * Shared lifecycle dispatcher.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $action  Action name.
	 */
	private function lifecycle( WP_REST_Request $request, string $action ) {
		$version  = $request->get_param( 'version' );
		$expected = null !== $version && '' !== $version ? (int) $version : null;
		$actor    = self::current_actor();
		$id       = (int) $request->get_param( 'id' );

		switch ( $action ) {
			case 'activate':
				$outcome = $this->waves->activate( $id, $actor, $expected );
				break;
			case 'pause':
				$outcome = $this->waves->pause( $id, $actor, $expected );
				break;
			case 'resume':
				$outcome = $this->waves->resume( $id, $actor, $expected );
				break;
			case 'abandon':
				$outcome = $this->waves->abandon( $id, $actor, $expected );
				break;
			default:
				return self::failure_error( 'invalid_payload', 'Unknown lifecycle action.' );
		}

		return $this->outcome_response( $outcome );
	}

	/**
	 * Maps WaveOutcome to REST response.
	 *
	 * @param WaveOutcome $outcome        Outcome.
	 * @param int         $success_status Success status.
	 */
	private function outcome_response( WaveOutcome $outcome, int $success_status = 200 ) {
		if ( ! $outcome->is_success() ) {
			return self::failure_error( (string) $outcome->failure_code(), (string) $outcome->failure_message() );
		}

		$wave            = $outcome->wave();
		$body            = $this->wave_resource( $wave );
		$body['result']  = $outcome->code();
		$body['message'] = $outcome->message();
		$body['data']    = $outcome->data();

		return $this->respond( $body, $success_status );
	}

	/**
	 * Builds a wave response with optional progress and walk.
	 *
	 * @param Wave                      $wave     Wave.
	 * @param array<string, mixed>|null $progress Progress.
	 * @param array<string, mixed>|null $walk     Walk.
	 * @return array<string, mixed>
	 */
	private function wave_body( Wave $wave, ?array $progress = null, ?array $walk = null ): array {
		$body = $this->wave_resource( $wave );

		if ( null !== $progress ) {
			$body['progress'] = $progress;
		}

		if ( null !== $walk ) {
			$body['walk'] = $walk;
		}

		return $body;
	}

	/**
	 * Serializes a wave for REST.
	 *
	 * @param Wave|null $wave Wave.
	 * @return array<string, mixed>
	 */
	private function wave_resource( ?Wave $wave ): array {
		if ( null === $wave ) {
			return array();
		}

		return array(
			'id'            => (int) $wave->id(),
			'warehouse_id'  => $wave->warehouse_id(),
			'owner_user_id' => $wave->owner_user_id(),
			'state'         => $wave->state(),
			'version'       => $wave->version(),
			'title'         => $wave->title(),
			'member_count'  => $wave->member_count(),
			'members'       => array_map(
				static function ( $member ): array {
					return array(
						'fulfillment_id' => $member->fulfillment_id(),
						'position'       => $member->position(),
						'picked_at'      => null === $member->picked_at() ? null : $member->picked_at()->format( 'c' ),
						'joined_at'      => $member->joined_at()->format( 'c' ),
					);
				},
				$wave->members()
			),
			'created_at'    => $wave->created_at()->format( 'c' ),
			'updated_at'    => $wave->updated_at()->format( 'c' ),
			'activated_at'  => null === $wave->activated_at() ? null : $wave->activated_at()->format( 'c' ),
			'completed_at'  => null === $wave->completed_at() ? null : $wave->completed_at()->format( 'c' ),
			'abandoned_at'  => null === $wave->abandoned_at() ? null : $wave->abandoned_at()->format( 'c' ),
		);
	}
}
