<?php
/**
 * REST controller for Workspace Scan Mode.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Api\Rest;

use MPCF\Application\Scan\ScanOutcome;
use MPCF\Application\Scan\ScanService;
use MPCF\Application\WorkflowService;
use MPCF\Capabilities;
use WP_REST_Request;
use WP_REST_Server;

/**
 * `POST /fulfillments/{id}/scan` — Part IX.11 smallest coherent contract.
 */
final class ScanController extends AbstractRestController {

	/**
	 * Scan mutations.
	 *
	 * @var ScanService
	 */
	private ScanService $scans;

	/**
	 * Transition envelope.
	 *
	 * @var WorkflowService
	 */
	private WorkflowService $workflow;

	/**
	 * Builds the controller.
	 *
	 * @param ScanService     $scans    Scan mutations.
	 * @param WorkflowService $workflow Transition envelope.
	 */
	public function __construct( ScanService $scans, WorkflowService $workflow ) {
		$this->scans    = $scans;
		$this->workflow = $workflow;
	}

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/fulfillments/(?P<id>\d+)/scan',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_scan' ),
					'permission_callback' => $this->require_capability( Capabilities::PROCESS_FULFILLMENTS ),
					'args'                => array(
						'id'                => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
						),
						'action'            => array(
							'type'              => 'string',
							'required'          => true,
							'enum'              => array( 'resolve', 'pick', 'pack', 'undo' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'payload'           => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => static function ( $value ): string {
								return is_string( $value ) ? trim( $value ) : '';
							},
						),
						'version'           => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'active_package_id' => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Handles one scan action.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function handle_scan( WP_REST_Request $request ) {
		$fulfillment_id = (int) $request->get_param( 'id' );
		$action         = (string) $request->get_param( 'action' );
		$payload        = (string) $request->get_param( 'payload' );
		$version        = $request->get_param( 'version' );
		$package_raw    = $request->get_param( 'active_package_id' );
		$package_id     = null !== $package_raw && (int) $package_raw > 0 ? (int) $package_raw : null;
		$actor          = self::current_actor();

		if ( in_array( $action, array( 'pick', 'pack', 'undo' ), true ) && ( null === $version || '' === $version ) ) {
			return self::failure_error( 'invalid_payload', 'version is required for mutating scan actions.' );
		}

		$expected = (int) $version;

		switch ( $action ) {
			case 'resolve':
				$outcome = $this->scans->resolve_scan( $fulfillment_id, $payload, null, $package_id );
				break;
			case 'pick':
				$outcome = $this->scans->scan_pick( $fulfillment_id, $expected, $payload, $actor, $package_id );
				break;
			case 'pack':
				$outcome = $this->scans->scan_pack( $fulfillment_id, $expected, $payload, $actor, $package_id );
				break;
			case 'undo':
				$outcome = $this->scans->undo_last_scan( $fulfillment_id, $expected, $actor );
				break;
			default:
				return self::failure_error( 'invalid_payload', 'Unknown scan action.' );
		}

		if ( ! $outcome->is_success() ) {
			return self::failure_error( (string) $outcome->failure_code(), (string) $outcome->failure_message() );
		}

		return $this->respond( $this->success_body( $fulfillment_id, $outcome ) );
	}

	/**
	 * Builds the success response body.
	 *
	 * @param int         $fulfillment_id Fulfillment id.
	 * @param ScanOutcome $outcome        Success outcome.
	 * @return array<string, mixed>
	 */
	private function success_body( int $fulfillment_id, ScanOutcome $outcome ): array {
		$candidates = $this->workflow->available_transitions( $fulfillment_id, 'current_user_can' );
		$resolution = $outcome->resolution();
		$body       = array(
			'result'            => $outcome->code(),
			'message'           => $outcome->message(),
			'version'           => $outcome->version(),
			'stage_complete'    => $outcome->stage_complete(),
			'active_package_id' => $outcome->active_package_id(),
			'progress'          => $outcome->progress(),
			'items'             => array_map( array( self::class, 'item_resource' ), $outcome->items() ),
			'transitions'       => self::transitions_resource( $candidates ),
		);

		if ( null !== $outcome->item() ) {
			$body['item'] = self::item_resource( $outcome->item() );
		}

		if ( null !== $resolution ) {
			$body['resolution'] = array(
				'status'      => $resolution->status(),
				'code'        => $resolution->code(),
				'source'      => $resolution->source(),
				'identity_id' => $resolution->identity_id(),
			);
		}

		return $body;
	}
}
