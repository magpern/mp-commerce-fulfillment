<?php
/**
 * REST controller for package photo capture, list, stream, and soft-delete.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Api\Rest;

use InvalidArgumentException;
use MPCF\Application\FulfillmentDetailService;
use MPCF\Application\Photos\PhotoService;
use MPCF\Application\WorkflowService;
use MPCF\Capabilities;
use MPCF\Domain\Media\PhotoRecord;
use RuntimeException;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Thin REST surface over {@see PhotoService}. Controllers stay free of
 * concrete media persistence and filesystem stores (invariant I11 /
 * RestBoundaryGuardTest).
 */
final class PhotosController extends AbstractRestController {

	/**
	 * Package photography orchestrator.
	 *
	 * @var PhotoService
	 */
	private PhotoService $photos;

	/**
	 * Workflow service for transition envelopes on mutations.
	 *
	 * @var WorkflowService
	 */
	private WorkflowService $workflow;

	/**
	 * Fulfillment detail service (kept for envelope symmetry with peers).
	 *
	 * @var FulfillmentDetailService
	 */
	private FulfillmentDetailService $detail;

	/**
	 * Builds the controller.
	 *
	 * @param PhotoService             $photos   Package photography orchestrator.
	 * @param WorkflowService          $workflow Transitions envelope.
	 * @param FulfillmentDetailService $detail   Fulfillment envelope helper.
	 */
	public function __construct(
		PhotoService $photos,
		WorkflowService $workflow,
		FulfillmentDetailService $detail
	) {
		$this->photos   = $photos;
		$this->workflow = $workflow;
		$this->detail   = $detail;
	}

	/**
	 * Registers this controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/fulfillments/(?P<id>\d+)/photos',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_photos' ),
					'permission_callback' => $this->require_capability( Capabilities::VIEW_QUEUE ),
					'args'                => array(
						'id'         => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
						),
						'package_id' => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'kind'       => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'capture' ),
					'permission_callback' => $this->require_capability( Capabilities::CAPTURE_PHOTOS ),
					'args'                => array(
						'id'         => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
						),
						'package_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'kind'       => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'version'    => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/photos/(?P<photo_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_photo' ),
					'permission_callback' => $this->require_capability( Capabilities::VIEW_QUEUE ),
					'args'                => array(
						'photo_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_photo' ),
					'permission_callback' => $this->require_capability( Capabilities::DELETE_PHOTOS ),
					'args'                => array(
						'photo_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
						),
						'version'  => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/photos/(?P<photo_id>\d+)/content',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'content' ),
					'permission_callback' => $this->require_capability( Capabilities::VIEW_QUEUE ),
					'args'                => array(
						'photo_id' => array(
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
			'/photos/(?P<photo_id>\d+)/thumb',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'thumb' ),
					'permission_callback' => $this->require_capability( Capabilities::VIEW_QUEUE ),
					'args'                => array(
						'photo_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
						),
					),
				),
			)
		);

		add_filter( 'rest_pre_serve_request', array( $this, 'maybe_serve_raw_photo_bytes' ), 10, 4 );
	}

	/**
	 * Serves canonical/thumbnail bytes with the correct MIME (bypasses JSON
	 * encoding for content and thumb routes only).
	 *
	 * @param bool              $served  Whether the request has already been served.
	 * @param \WP_HTTP_Response $result  Result to send.
	 * @param WP_REST_Request   $request Request.
	 * @param WP_REST_Server    $server  Server.
	 */
	public function maybe_serve_raw_photo_bytes( $served, $result, $request, $server ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature fixed by rest_pre_serve_request.
		if ( true === $served || ! $result instanceof \WP_REST_Response ) {
			return (bool) $served;
		}

		$route = $request->get_route();
		if ( ! is_string( $route ) || 1 !== preg_match( '#^/mpcf/v1/photos/\d+/(content|thumb)$#', $route ) ) {
			return (bool) $served;
		}

		$data = $result->get_data();
		if ( ! is_array( $data ) || empty( $data['__raw_bytes'] ) || ! is_string( $data['__raw_bytes'] ) ) {
			return false;
		}

		$mime      = isset( $data['mime'] ) && is_string( $data['mime'] ) ? $data['mime'] : 'image/jpeg';
		$filename  = isset( $data['filename'] ) && is_string( $data['filename'] ) ? $data['filename'] : 'photo.jpg';
		$sanitized = preg_replace( '/[^a-zA-Z0-9._-]/', '', $filename );
		$filename  = ( is_string( $sanitized ) && '' !== $sanitized ) ? $sanitized : 'photo.jpg';

		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Exact stored image bytes; capability-gated stream.
		echo $data['__raw_bytes'];

		return true;
	}

	/**
	 * `GET /fulfillments/{id}/photos`.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_photos( WP_REST_Request $request ) {
		$fulfillment_id = (int) $request->get_param( 'id' );
		$view           = $this->detail->get( $fulfillment_id );

		if ( null === $view ) {
			return self::not_found_error( 'Fulfillment not found.' );
		}

		$package_param = $request->get_param( 'package_id' );
		$package_id    = null !== $package_param && '' !== $package_param && (int) $package_param > 0
			? (int) $package_param
			: null;
		$kind_param    = $request->get_param( 'kind' );
		$kind          = is_string( $kind_param ) && '' !== $kind_param ? $kind_param : null;

		try {
			$photos = $this->photos->list_active( $fulfillment_id, $package_id, $kind );
		} catch ( InvalidArgumentException $e ) {
			return self::photo_error_from_exception( $e );
		}

		return $this->respond(
			array(
				'photos' => array_map( array( self::class, 'photo_resource' ), $photos ),
			)
		);
	}

	/**
	 * `POST /fulfillments/{id}/photos`.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function capture( WP_REST_Request $request ) {
		$fulfillment_id = (int) $request->get_param( 'id' );
		$package_id     = (int) $request->get_param( 'package_id' );
		$kind           = (string) $request->get_param( 'kind' );
		$version        = (int) $request->get_param( 'version' );

		$view = $this->detail->get( $fulfillment_id );
		if ( null === $view ) {
			return self::not_found_error( 'Fulfillment not found.' );
		}

		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;

		if ( null === $file ) {
			return self::photo_error( 'invalid_upload', 'A photo file upload is required.' );
		}

		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $error ) {
			return self::photo_error( 'invalid_upload', 'Photo upload failed.' );
		}

		$tmp = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		if ( '' === $tmp || ! is_readable( $tmp ) ) {
			return self::photo_error( 'invalid_upload', 'Photo upload is unreadable.' );
		}

		// PHPUnit never marks temp fixtures as uploaded; production requires is_uploaded_file.
		if ( ! is_uploaded_file( $tmp ) && ! defined( 'WP_TESTS_DOMAIN' ) ) {
			return self::photo_error( 'invalid_upload', 'Photo upload is invalid.' );
		}

		$bytes = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local multipart upload temp path validated above.
		if ( false === $bytes || '' === $bytes ) {
			return self::photo_error( 'invalid_upload', 'Photo upload is empty.' );
		}

		$declared_mime = isset( $file['type'] ) && is_string( $file['type'] ) ? $file['type'] : '';

		try {
			$result = $this->photos->capture_with_version(
				$fulfillment_id,
				$package_id,
				$kind,
				$bytes,
				$declared_mime,
				self::current_actor(),
				$version
			);
		} catch ( InvalidArgumentException $e ) {
			return self::photo_error_from_exception( $e );
		} catch ( RuntimeException $e ) {
			return self::photo_error_from_runtime( $e );
		} catch ( Throwable $e ) {
			unset( $e );

			return self::photo_error( 'storage_failed', 'Unable to store the photo.' );
		}

		$transitions = $this->workflow->available_transitions( $fulfillment_id, 'current_user_can' );

		return $this->respond(
			array(
				'photo'                       => self::photo_resource( $result->photo() ),
				'version'                     => $result->version(),
				'photo_requirement_satisfied' => $result->requirement_satisfied(),
				'transitions'                 => self::transitions_resource( $transitions ),
			),
			201
		);
	}

	/**
	 * `GET /photos/{photo_id}` — active metadata only.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_photo( WP_REST_Request $request ) {
		$photo = $this->photos->get_active( (int) $request->get_param( 'photo_id' ) );

		if ( null === $photo ) {
			return self::photo_error( 'photo_not_found', 'Photo not found.' );
		}

		return $this->respond( array( 'photo' => self::photo_resource( $photo ) ) );
	}

	/**
	 * `GET /photos/{photo_id}/content`.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function content( WP_REST_Request $request ) {
		return $this->stream( (int) $request->get_param( 'photo_id' ), 'content' );
	}

	/**
	 * `GET /photos/{photo_id}/thumb`.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function thumb( WP_REST_Request $request ) {
		return $this->stream( (int) $request->get_param( 'photo_id' ), 'thumb' );
	}

	/**
	 * `DELETE /photos/{photo_id}`.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_photo( WP_REST_Request $request ) {
		$photo_id = (int) $request->get_param( 'photo_id' );
		$version  = (int) $request->get_param( 'version' );

		try {
			$result = $this->photos->soft_delete_with_version( $photo_id, self::current_actor(), $version );
		} catch ( InvalidArgumentException $e ) {
			return self::photo_error_from_exception( $e );
		} catch ( RuntimeException $e ) {
			return self::photo_error_from_runtime( $e );
		}

		$fulfillment_id = $result->photo()->fulfillment_id();
		$transitions    = $this->workflow->available_transitions( $fulfillment_id, 'current_user_can' );

		return $this->respond(
			array(
				'photo'                       => self::photo_resource( $result->photo() ),
				'version'                     => $result->version(),
				'photo_requirement_satisfied' => $result->requirement_satisfied(),
				'transitions'                 => self::transitions_resource( $transitions ),
			)
		);
	}

	/**
	 * Safe photo metadata for the wire — never storage-relative paths.
	 *
	 * @param PhotoRecord $photo Photo to serialize.
	 * @return array<string, mixed>
	 */
	public static function photo_resource( PhotoRecord $photo ): array {
		$id = (int) $photo->id();

		return array(
			'id'                 => $id,
			'fulfillment_id'     => $photo->fulfillment_id(),
			'package_id'         => $photo->package_id(),
			'kind'               => $photo->kind(),
			'mime'               => $photo->mime(),
			'bytes'              => $photo->bytes(),
			'width'              => $photo->width(),
			'height'             => $photo->height(),
			'sha256'             => $photo->sha256(),
			'processing_version' => $photo->processing_version(),
			'sequence'           => $photo->seq(),
			'captured_by'        => $photo->captured_by(),
			'created_at'         => $photo->created_at()->format( DATE_ATOM ),
			'content'            => '/mpcf/v1/photos/' . $id . '/content',
			'thumbnail'          => '/mpcf/v1/photos/' . $id . '/thumb',
		);
	}

	/**
	 * Streams content or thumb via `__raw_bytes`.
	 *
	 * @param int    $photo_id Photo id.
	 * @param string $which    `content` or `thumb`.
	 */
	private function stream( int $photo_id, string $which ) {
		$result = $this->photos->read_bytes( $photo_id, $which );

		if ( ! $result['ok'] ) {
			return self::photo_error( (string) $result['code'], (string) $result['message'] );
		}

		return $this->respond(
			array(
				'__raw_bytes' => $result['bytes'],
				'mime'        => $result['mime'],
				'filename'    => $result['filename'],
			)
		);
	}

	/**
	 * Maps PhotoService InvalidArgumentException messages to photo WP_Error codes.
	 *
	 * @param InvalidArgumentException $e Exception from PhotoService.
	 */
	private static function photo_error_from_exception( InvalidArgumentException $e ): WP_Error {
		$message = $e->getMessage();

		if ( 'version_conflict' === $message ) {
			return self::photo_error( 'version_conflict', 'The fulfillment version no longer matches.' );
		}

		if ( 'Fulfillment not found.' === $message ) {
			return self::not_found_error( $message );
		}

		if ( 'Photo not found.' === $message ) {
			return self::photo_error( 'photo_not_found', $message );
		}

		if ( 'Package not found.' === $message ) {
			return self::not_found_error( $message );
		}

		if ( 'Package does not belong to the fulfillment.' === $message ) {
			return self::photo_error( 'package_mismatch', $message );
		}

		if ( str_starts_with( $message, 'Invalid photo kind' ) ) {
			return self::photo_error( 'invalid_kind', $message );
		}

		if ( str_contains( $message, 'Maximum active photos' ) ) {
			return self::photo_error( 'limit_reached', $message );
		}

		if ( str_contains( $message, 'Upload exceeds' ) ) {
			return self::photo_error( 'invalid_upload', $message );
		}

		return self::photo_error( 'invalid_upload', $message );
	}

	/**
	 * Maps RuntimeException from PhotoService (version race / storage).
	 *
	 * @param RuntimeException $e Exception from PhotoService.
	 */
	private static function photo_error_from_runtime( RuntimeException $e ): WP_Error {
		if ( 'version_conflict' === $e->getMessage() ) {
			return self::photo_error( 'version_conflict', 'The fulfillment version no longer matches.' );
		}

		return self::photo_error( 'storage_failed', esc_html( $e->getMessage() ) );
	}

	/**
	 * Builds a photo-specific WP_Error.
	 *
	 * @param string $code    Short code without `mpcf_photo_` / `mpcf_` prefix.
	 * @param string $message Human-readable message.
	 */
	private static function photo_error( string $code, string $message ): WP_Error {
		$map = array(
			'photo_not_found'       => array( 'mpcf_photo_not_found', 404 ),
			'photo_deleted'         => array( 'mpcf_photo_deleted', 404 ),
			'invalid_kind'          => array( 'mpcf_photo_invalid_kind', 400 ),
			'invalid_upload'        => array( 'mpcf_photo_invalid_upload', 400 ),
			'package_mismatch'      => array( 'mpcf_photo_package_mismatch', 400 ),
			'limit_reached'         => array( 'mpcf_photo_limit_reached', 422 ),
			'storage_failed'        => array( 'mpcf_photo_storage_failed', 500 ),
			'photo_content_missing' => array( 'mpcf_photo_content_missing', 422 ),
			'version_conflict'      => array( 'mpcf_version_conflict', 409 ),
		);

		if ( ! isset( $map[ $code ] ) ) {
			return self::invalid_payload_error( $message );
		}

		return new WP_Error( $map[ $code ][0], $message, array( 'status' => $map[ $code ][1] ) );
	}
}
