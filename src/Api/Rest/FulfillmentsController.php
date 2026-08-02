<?php
/**
 * REST controller for fulfillments and their transitions.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Api\Rest;

use MPCF\Application\FulfillmentDetailService;
use MPCF\Application\QueueService;
use MPCF\Application\WorkflowService;
use MPCF\Capabilities;
use MPCF\Domain\FulfillmentQuery;
use MPCF\Domain\Workflow\WorkflowDefinition;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET /fulfillments`, `GET /fulfillments/{id}`,
 * `GET|POST /fulfillments/{id}/transitions` — the read side reuses
 * {@see QueueService}/{@see FulfillmentDetailService} exactly as the Queue
 * and Fulfillment Detail admin screens do (invariant I11); the one
 * mutation reuses {@see WorkflowService::transition()}, the identical
 * object {@see \MPCF\Admin\FulfillmentDetailPage::submit_transition()}
 * calls (Architecture Plan §IV.15 criterion 2: the two paths must produce
 * identical outcomes).
 */
final class FulfillmentsController extends AbstractRestController {

	/**
	 * Read-side fulfillment listing.
	 *
	 * @var QueueService
	 */
	private QueueService $queue;

	/**
	 * Read-side fulfillment detail aggregation.
	 *
	 * @var FulfillmentDetailService
	 */
	private FulfillmentDetailService $detail;

	/**
	 * Transition mutations and candidate evaluation.
	 *
	 * @var WorkflowService
	 */
	private WorkflowService $workflow;

	/**
	 * The governing workflow — resolves a candidate edge's required
	 * capability before {@see WorkflowService::transition()} is called,
	 * the same lookup {@see \MPCF\Admin\FulfillmentDetailPage::submit_transition()}
	 * performs.
	 *
	 * @var WorkflowDefinition
	 */
	private WorkflowDefinition $definition;

	/**
	 * Builds the controller.
	 *
	 * @param QueueService             $queue      Read-side fulfillment listing.
	 * @param FulfillmentDetailService $detail     Read-side fulfillment detail aggregation.
	 * @param WorkflowService          $workflow   Transition mutations and candidate evaluation.
	 * @param WorkflowDefinition       $definition The governing workflow.
	 */
	public function __construct(
		QueueService $queue,
		FulfillmentDetailService $detail,
		WorkflowService $workflow,
		WorkflowDefinition $definition
	) {
		$this->queue      = $queue;
		$this->detail     = $detail;
		$this->workflow   = $workflow;
		$this->definition = $definition;
	}

	/**
	 * Registers this controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/fulfillments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_fulfillments' ),
					'permission_callback' => $this->require_capability( Capabilities::VIEW_QUEUE ),
					'args'                => array(
						'state'    => array(
							'type'              => 'array',
							'items'             => array( 'type' => 'string' ),
							'default'           => array(),
							'sanitize_callback' => static fn( $value ) => array_map( 'sanitize_key', (array) $value ),
						),
						'assignee' => array(
							'sanitize_callback' => static fn( $value ) => is_numeric( $value ) ? (int) $value : sanitize_key( (string) $value ),
						),
						'search'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'order_by' => array(
							'type'              => 'string',
							'default'           => 'created_at',
							'sanitize_callback' => 'sanitize_key',
						),
						'order'    => array(
							'type'              => 'string',
							'default'           => 'DESC',
							'sanitize_callback' => static fn( $value ) => 'ASC' === strtoupper( (string) $value ) ? 'ASC' : 'DESC',
						),
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/fulfillments/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_fulfillment' ),
					'permission_callback' => $this->require_capability( Capabilities::VIEW_QUEUE ),
					'args'                => self::id_arg(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/fulfillments/(?P<id>\d+)/transitions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_transitions' ),
					'permission_callback' => $this->require_capability( Capabilities::VIEW_QUEUE ),
					'args'                => self::id_arg(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit_transition' ),
					'permission_callback' => $this->require_capability( Capabilities::VIEW_QUEUE ),
					'args'                => array_merge(
						self::id_arg(),
						array(
							'target'  => array(
								'type'              => 'string',
								'required'          => true,
								'sanitize_callback' => 'sanitize_key',
							),
							'reason'  => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_textarea_field',
							),
							'version' => array(
								'type'              => 'integer',
								'required'          => true,
								'sanitize_callback' => 'absint',
							),
						)
					),
				),
			)
		);
	}

	/**
	 * `GET /fulfillments`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function list_fulfillments( WP_REST_Request $request ): WP_REST_Response {
		$query = new FulfillmentQuery(
			(array) $request->get_param( 'state' ),
			$this->assignee_param( $request ),
			null,
			null,
			(string) $request->get_param( 'order_by' ),
			(string) $request->get_param( 'order' ),
			(int) $request->get_param( 'page' ),
			(int) $request->get_param( 'per_page' )
		);

		$search = $request->get_param( 'search' );
		$result = $this->queue->list( $query, is_string( $search ) ? $search : null );

		return $this->respond(
			array(
				'items'       => array_map( array( self::class, 'fulfillment_resource' ), $result->items() ),
				'total'       => $result->total(),
				'page'        => $result->page(),
				'per_page'    => $result->per_page(),
				'total_pages' => $result->total_pages(),
			)
		);
	}

	/**
	 * `GET /fulfillments/{id}`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_fulfillment( WP_REST_Request $request ) {
		$view = $this->detail->get( (int) $request->get_param( 'id' ) );

		if ( null === $view ) {
			return self::not_found_error( 'No fulfillment exists with this id.' );
		}

		// The workspace's outcome column shows only the last five events
		// (Architecture Plan §IV.5.2); the full, unbounded chain stays a
		// Fulfillment Detail concern until §IV.10's timeline pagination
		// (F23) gives it a real page size.
		$recent_events = array_slice( $view->timeline(), -5 );

		return $this->respond(
			array(
				'fulfillment'   => self::fulfillment_resource( $view->fulfillment() ),
				'items'         => array_map( array( self::class, 'item_resource' ), $view->items() ),
				'recent_events' => $recent_events,
			)
		);
	}

	/**
	 * `GET /fulfillments/{id}/transitions`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function list_transitions( WP_REST_Request $request ): WP_REST_Response {
		$candidates = $this->workflow->available_transitions( (int) $request->get_param( 'id' ), 'current_user_can' );

		return $this->respond( array( 'transitions' => self::transitions_resource( $candidates ) ) );
	}

	/**
	 * `POST /fulfillments/{id}/transitions`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function submit_transition( WP_REST_Request $request ) {
		$fulfillment_id = (int) $request->get_param( 'id' );
		$target         = (string) $request->get_param( 'target' );
		$reason         = $request->get_param( 'reason' );

		$view = $this->detail->get( $fulfillment_id );

		if ( null === $view ) {
			return self::not_found_error( "No fulfillment exists with id {$fulfillment_id}." );
		}

		$transition = $this->definition->transition( $view->fulfillment()->state(), $target );
		$capability = null !== $transition ? $transition->required_capability() : Capabilities::PROCESS_FULFILLMENTS;

		if ( ! current_user_can( $capability ) ) {
			return self::forbidden_error( 'You are not allowed to make this change.' );
		}

		// Architecture Plan §IV.5.7: every mutating request carries the
		// fulfillment's version; a mismatch is a 409 before anything is
		// attempted — WorkflowService::transition() itself has no concept
		// of a caller-asserted version, since its own optimistic lock is
		// against whatever it just read, not what the client last saw.
		if ( (int) $request->get_param( 'version' ) !== $view->fulfillment()->version() ) {
			return new WP_Error(
				'mpcf_version_conflict',
				'Someone else updated this fulfillment. Reload and try again.',
				array( 'status' => 409 )
			);
		}

		$outcome = $this->workflow->transition( $fulfillment_id, $target, self::current_actor(), is_string( $reason ) ? $reason : null );

		if ( ! $outcome->is_success() ) {
			return self::failure_error( (string) $outcome->failure_code(), (string) $outcome->failure_message() );
		}

		$candidates = $this->workflow->available_transitions( $fulfillment_id, 'current_user_can' );

		return $this->respond(
			array(
				'fulfillment' => self::fulfillment_resource( $outcome->fulfillment() ),
				'transitions' => self::transitions_resource( $candidates ),
			)
		);
	}

	/**
	 * The `id` path-parameter arg schema every single-fulfillment route shares.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function id_arg(): array {
		return array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
			),
		);
	}

	/**
	 * Resolves the `assignee` query param to what {@see FulfillmentQuery}
	 * expects: an int user id, the unassigned sentinel, or null.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return int|string|null
	 */
	private function assignee_param( WP_REST_Request $request ) {
		$assignee = $request->get_param( 'assignee' );

		if ( null === $assignee || '' === $assignee ) {
			return null;
		}

		return $assignee;
	}
}
