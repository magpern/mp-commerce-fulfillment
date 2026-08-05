<?php
/**
 * REST controller for document rendering.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Api\Rest;

use MPCF\Application\DocumentService;
use MPCF\Application\FulfillmentDetailService;
use MPCF\Application\WorkflowService;
use MPCF\Capabilities;
use WP_REST_Request;
use WP_REST_Server;

/**
 * `POST /fulfillments/{id}/documents/render` — thin controller over
 * {@see DocumentService}. Accepts optional `doc_type` (defaults to
 * packing_slip for M2 compatibility). Returns rendered HTML for browser
 * printing plus storage metadata (M4-B/C).
 */
final class DocumentsController extends AbstractRestController {

	/**
	 * Document assembly/render/record orchestration.
	 *
	 * @var DocumentService
	 */
	private DocumentService $documents;

	/**
	 * Fresh candidate-transition evaluation for the response envelope.
	 *
	 * @var WorkflowService
	 */
	private WorkflowService $workflow;

	/**
	 * The owning fulfillment, for the response envelope.
	 *
	 * @var FulfillmentDetailService
	 */
	private FulfillmentDetailService $detail;

	/**
	 * Builds the controller.
	 *
	 * @param DocumentService          $documents Document assembly/render/record orchestration.
	 * @param WorkflowService          $workflow  Fresh candidate-transition evaluation for the response envelope.
	 * @param FulfillmentDetailService $detail    The owning fulfillment, for the response envelope.
	 */
	public function __construct( DocumentService $documents, WorkflowService $workflow, FulfillmentDetailService $detail ) {
		$this->documents = $documents;
		$this->workflow  = $workflow;
		$this->detail    = $detail;
	}

	/**
	 * Registers this controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/fulfillments/(?P<id>\d+)/documents/render',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'render' ),
					'permission_callback' => $this->require_capability( Capabilities::RENDER_DOCUMENTS ),
					'args'                => array(
						'id'       => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
						),
						'doc_type' => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'packing_slip',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);
	}

	/**
	 * `POST /fulfillments/{id}/documents/render`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function render( WP_REST_Request $request ) {
		$fulfillment_id = (int) $request->get_param( 'id' );
		$doc_type       = (string) $request->get_param( 'doc_type' );

		if ( '' === $doc_type ) {
			$doc_type = 'packing_slip';
		}

		$outcome = $this->documents->render(
			$fulfillment_id,
			$doc_type,
			array(
				'actor' => self::current_actor(),
				'can'   => static fn( string $capability ): bool => current_user_can( $capability ),
			)
		);

		if ( ! $outcome->is_success() ) {
			return self::failure_error( (string) $outcome->failure_code(), (string) $outcome->failure_message() );
		}

		$view        = $this->detail->get( $fulfillment_id );
		$transitions = $this->workflow->available_transitions( $fulfillment_id, 'current_user_can' );
		$meta        = $outcome->meta();

		return $this->respond(
			array(
				'html'             => $outcome->html(),
				'document_id'      => $outcome->document_id(),
				'document_type'    => (string) ( $meta['document_type'] ?? $doc_type ),
				'template_version' => (string) ( $meta['template_version'] ?? '' ),
				'stored'           => (bool) ( $meta['stored'] ?? false ),
				'file_available'   => (bool) ( $meta['file_available'] ?? false ),
				'fulfillment'      => null !== $view ? self::fulfillment_resource( $view->fulfillment() ) : null,
				'transitions'      => self::transitions_resource( $transitions ),
			),
			201
		);
	}
}
