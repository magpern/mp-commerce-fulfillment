<?php
/**
 * REST controller for fulfillment notes.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Api\Rest;

use MPCF\Application\NoteService;
use MPCF\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET|POST /fulfillments/{id}/notes` — reuses {@see NoteService} exactly
 * as {@see \MPCF\Admin\FulfillmentDetailPage} does (invariant I11).
 */
final class NotesController extends AbstractRestController {

	/**
	 * Note reads and mutations.
	 *
	 * @var NoteService
	 */
	private NoteService $notes;

	/**
	 * Builds the controller.
	 *
	 * @param NoteService $notes Note reads and mutations.
	 */
	public function __construct( NoteService $notes ) {
		$this->notes = $notes;
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
			'/fulfillments/(?P<id>\d+)/notes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_notes' ),
					'permission_callback' => $this->require_capability( Capabilities::VIEW_QUEUE ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_note' ),
					'permission_callback' => $this->require_capability( Capabilities::ADD_NOTES ),
					'args'                => array_merge(
						$id_arg,
						array(
							'body'      => array(
								'type'              => 'string',
								'required'          => true,
								'sanitize_callback' => 'sanitize_textarea_field',
							),
							'is_pinned' => array(
								'type'              => 'boolean',
								'default'           => false,
								'sanitize_callback' => 'rest_sanitize_boolean',
							),
						)
					),
				),
			)
		);
	}

	/**
	 * `GET /fulfillments/{id}/notes`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function list_notes( WP_REST_Request $request ): WP_REST_Response {
		$notes = $this->notes->list_for( (int) $request->get_param( 'id' ) );

		return $this->respond( array( 'notes' => array_map( array( self::class, 'note_resource' ), $notes ) ) );
	}

	/**
	 * `POST /fulfillments/{id}/notes`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function add_note( WP_REST_Request $request ) {
		$body = (string) $request->get_param( 'body' );

		if ( '' === trim( $body ) ) {
			return self::invalid_payload_error( 'A note cannot be empty.' );
		}

		$note = $this->notes->add(
			(int) $request->get_param( 'id' ),
			get_current_user_id(),
			$body,
			(bool) $request->get_param( 'is_pinned' )
		);

		return $this->respond( array( 'note' => self::note_resource( $note ) ), 201 );
	}
}
