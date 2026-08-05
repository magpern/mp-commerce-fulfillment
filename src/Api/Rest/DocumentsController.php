<?php
/**
 * REST controller for document render, history, and content streaming.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Api\Rest;

use MPCF\Application\DocumentHistoryService;
use MPCF\Application\DocumentService;
use MPCF\Application\FulfillmentDetailService;
use MPCF\Application\WorkflowService;
use MPCF\Capabilities;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Thin REST surface over {@see DocumentService} and {@see DocumentHistoryService}.
 */
final class DocumentsController extends AbstractRestController {

	/**
	 * @var DocumentService
	 */
	private DocumentService $documents;

	/**
	 * @var DocumentHistoryService
	 */
	private DocumentHistoryService $history;

	/**
	 * @var WorkflowService
	 */
	private WorkflowService $workflow;

	/**
	 * @var FulfillmentDetailService
	 */
	private FulfillmentDetailService $detail;

	/**
	 * @param DocumentService          $documents Fresh render orchestrator.
	 * @param DocumentHistoryService   $history   History / stream / reprint.
	 * @param WorkflowService          $workflow  Transitions envelope.
	 * @param FulfillmentDetailService $detail    Fulfillment envelope.
	 */
	public function __construct(
		DocumentService $documents,
		DocumentHistoryService $history,
		WorkflowService $workflow,
		FulfillmentDetailService $detail
	) {
		$this->documents = $documents;
		$this->history   = $history;
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

		register_rest_route(
			self::NAMESPACE_V1,
			'/documents',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_documents' ),
					'permission_callback' => $this->require_capability( Capabilities::RENDER_DOCUMENTS ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/documents/(?P<document_id>\d+)/content',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'content' ),
					'permission_callback' => $this->require_capability( Capabilities::RENDER_DOCUMENTS ),
					'args'                => array(
						'document_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/documents/(?P<document_id>\d+)/reprint',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reprint' ),
					'permission_callback' => $this->require_capability( Capabilities::RENDER_DOCUMENTS ),
					'args'                => array(
						'document_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
						),
					),
				),
			)
		);

		add_filter( 'rest_pre_serve_request', array( $this, 'maybe_serve_raw_document_html' ), 10, 4 );
	}

	/**
	 * Serves stored document HTML as raw bytes with the correct MIME
	 * (bypasses JSON encoding for the content route only).
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param \WP_HTTP_Response $result Result to send.
	 * @param WP_REST_Request  $request Request.
	 * @param WP_REST_Server   $server  Server.
	 */
	public function maybe_serve_raw_document_html( $served, $result, $request, $server ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature fixed by rest_pre_serve_request.
		if ( true === $served || ! $result instanceof \WP_REST_Response ) {
			return (bool) $served;
		}

		$route = $request->get_route();
		if ( ! is_string( $route ) || 1 !== preg_match( '#^/mpcf/v1/documents/\d+/content$#', $route ) ) {
			return (bool) $served;
		}

		$data = $result->get_data();
		if ( ! is_array( $data ) || empty( $data['__raw_html'] ) || ! is_string( $data['__raw_html'] ) ) {
			return false;
		}

		$mime     = isset( $data['mime'] ) && is_string( $data['mime'] ) ? $data['mime'] : 'text/html; charset=UTF-8';
		$filename = isset( $data['filename'] ) && is_string( $data['filename'] ) ? $data['filename'] : 'document.html';
		$filename = preg_replace( '/[^a-zA-Z0-9._-]/', '', $filename ) ?: 'document.html';

		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Exact stored HTML artifact; capability-gated stream.
		echo $data['__raw_html'];

		return true;
	}

	/**
	 * Fresh render.
	 *
	 * @param WP_REST_Request $request Request.
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

	/**
	 * History list.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_documents( WP_REST_Request $request ) {
		$result = $this->history->search(
			array(
				'doc_type'  => (string) $request->get_param( 'doc_type' ),
				'search'    => (string) $request->get_param( 'search' ),
				'date_from' => (string) $request->get_param( 'date_from' ),
				'date_to'   => (string) $request->get_param( 'date_to' ),
				'limit'     => (int) ( $request->get_param( 'limit' ) ?: 50 ),
				'offset'    => (int) ( $request->get_param( 'offset' ) ?: 0 ),
			)
		);

		return $this->respond( $result );
	}

	/**
	 * Stream exact stored HTML (no reprint event).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function content( WP_REST_Request $request ) {
		$document_id = (int) $request->get_param( 'document_id' );
		$result      = $this->history->read_content( $document_id );

		if ( ! $result['ok'] ) {
			return self::failure_error( (string) $result['code'], (string) $result['message'] );
		}

		$doc_type = $result['record']->doc_type();

		return $this->respond(
			array(
				'__raw_html' => $result['html'],
				'mime'       => $result['mime'],
				'filename'   => 'mpcf-' . $doc_type . '-' . $document_id . '.html',
			)
		);
	}

	/**
	 * Exact reprint with document.reprinted audit.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function reprint( WP_REST_Request $request ) {
		$result = $this->history->reprint( (int) $request->get_param( 'document_id' ), self::current_actor() );

		if ( ! $result['ok'] ) {
			return self::failure_error( (string) $result['code'], (string) $result['message'] );
		}

		return $this->respond(
			array(
				'html'                => $result['html'],
				'document_id'         => (int) $result['record']->id(),
				'source_document_id'  => (int) $result['record']->id(),
				'document_type'       => $result['record']->doc_type(),
				'template_version'    => $result['record']->template_version(),
				'mime'                => $result['mime'],
			)
		);
	}
}
