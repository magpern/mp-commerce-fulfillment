<?php
/**
 * REST endpoints for shipment notification send/status.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Api\Rest;

use MPCF\Application\Notifications\NotificationService;
use MPCF\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Minimal notification surface for M5-C/D. Does not expose transport internals.
 */
final class NotificationsController extends AbstractRestController {

	/**
	 * Notification service.
	 *
	 * @var NotificationService
	 */
	private NotificationService $notifications;

	/**
	 * Builds the controller.
	 *
	 * @param NotificationService $notifications Notification service.
	 */
	public function __construct( NotificationService $notifications ) {
		$this->notifications = $notifications;
	}

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/shipments/(?P<id>\d+)/notify',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'notify' ),
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_SHIPMENTS ),
					'args'                => array(
						'id'    => array(
							'required' => true,
							'type'     => 'integer',
						),
						'force' => array(
							'required' => false,
							'type'     => 'boolean',
							'default'  => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/shipments/(?P<id>\d+)/notification-status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'status' ),
					'permission_callback' => $this->require_capability( Capabilities::VIEW_QUEUE ),
					'args'                => array(
						'id' => array(
							'required' => true,
							'type'     => 'integer',
						),
					),
				),
			)
		);
	}

	/**
	 * POST /shipments/{id}/notify
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function notify( WP_REST_Request $request ) {
		$shipment_id = (int) $request->get_param( 'id' );
		$force       = (bool) $request->get_param( 'force' );
		$outcome     = $this->notifications->notify_shipment( $shipment_id, self::current_actor(), $force );

		if ( 'not_found' === $outcome['status'] ) {
			return self::failure_error( 'not_found', 'Shipment not found.' );
		}

		$result = $outcome['result'];

		return $this->respond(
			array(
				'status'   => $outcome['status'],
				'strategy' => $outcome['strategy'],
				'result'   => null === $result ? null : $result->to_audit_array(),
			)
		);
	}

	/**
	 * GET /shipments/{id}/notification-status
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response {
		$status = $this->notifications->status_for_shipment( (int) $request->get_param( 'id' ) );

		return $this->respond(
			array(
				'notification' => $status,
			)
		);
	}
}
