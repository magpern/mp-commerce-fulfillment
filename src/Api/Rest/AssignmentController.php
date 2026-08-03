<?php
/**
 * REST controller for fulfillment assignment.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Api\Rest;

use MPCF\Application\AssignmentService;
use MPCF\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `PUT|DELETE /fulfillments/{id}/assignment` — reuses
 * {@see AssignmentService} exactly as the Queue's bulk-assign action does
 * (invariant I11). Assignment is plain metadata, never guard-checked
 * (matching {@see AssignmentService}'s own docblock), so unlike
 * `/transitions` and `/items` this route carries no client-asserted
 * `version` — {@see AssignmentService::assign()}/{@see AssignmentService::unassign()}
 * already enforce the optimistic lock internally against whatever they
 * just read, the same way {@see \MPCF\Admin\QueuePage}'s bulk action
 * already relies on.
 */
final class AssignmentController extends AbstractRestController {

	/**
	 * Assignment mutations.
	 *
	 * @var AssignmentService
	 */
	private AssignmentService $assignments;

	/**
	 * Builds the controller.
	 *
	 * @param AssignmentService $assignments Assignment mutations.
	 */
	public function __construct( AssignmentService $assignments ) {
		$this->assignments = $assignments;
	}

	/**
	 * Registers this controller's routes.
	 */
	public function register_routes(): void {
		$id_arg = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
			),
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/fulfillments/(?P<id>\d+)/assignment',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'assign' ),
					'permission_callback' => $this->require_capability( Capabilities::PROCESS_FULFILLMENTS ),
					'args'                => array_merge(
						$id_arg,
						array(
							'user_id' => array(
								'type'              => 'integer',
								'required'          => true,
								'sanitize_callback' => 'absint',
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unassign' ),
					'permission_callback' => $this->require_capability( Capabilities::PROCESS_FULFILLMENTS ),
					'args'                => $id_arg,
				),
			)
		);
	}

	/**
	 * `PUT /fulfillments/{id}/assignment`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function assign( WP_REST_Request $request ) {
		$succeeded = $this->assignments->assign( (int) $request->get_param( 'id' ), (int) $request->get_param( 'user_id' ), self::current_actor() );

		return $this->respond_to_assignment_outcome( $succeeded );
	}

	/**
	 * `DELETE /fulfillments/{id}/assignment`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function unassign( WP_REST_Request $request ) {
		$succeeded = $this->assignments->unassign( (int) $request->get_param( 'id' ), self::current_actor() );

		return $this->respond_to_assignment_outcome( $succeeded );
	}

	/**
	 * Shared response shape for both mutations.
	 *
	 * @param bool $succeeded Whether the mutation succeeded.
	 */
	private function respond_to_assignment_outcome( bool $succeeded ) {
		if ( ! $succeeded ) {
			return self::not_found_error( 'No fulfillment exists with this id, or it was updated by someone else — reload and try again.' );
		}

		return $this->respond( array( 'success' => true ) );
	}
}
