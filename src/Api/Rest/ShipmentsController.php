<?php
/**
 * REST controller for shipments.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Api\Rest;

use MPCF\Application\FulfillmentDetailService;
use MPCF\Application\ShippingService;
use MPCF\Application\WorkflowService;
use MPCF\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET|POST /fulfillments/{id}/shipments`, `PATCH|DELETE /shipments/{id}`,
 * `POST /shipments/{id}/ship`, `POST /shipments/{id}/packages` — reuses
 * {@see ShippingService} exactly as a future workspace JS module will
 * (invariant I11). Every mutation response embeds the owning fulfillment
 * and its fresh candidate transitions (Architecture Plan §IV.9's
 * "response returns fresh state, no follow-up round trip" convention) —
 * shipment status is not fulfillment state (§IV.6), but a shipment/
 * package edit can flip `package_spec_present`/`has_shipment`, which
 * changes what the action bar's primary button should show.
 */
final class ShipmentsController extends AbstractRestController {

	/**
	 * Shipment/package mutations and reads.
	 *
	 * @var ShippingService
	 */
	private ShippingService $shipping;

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
	 * @param ShippingService          $shipping Shipment/package mutations and reads.
	 * @param WorkflowService          $workflow Fresh candidate-transition evaluation for the response envelope.
	 * @param FulfillmentDetailService $detail   The owning fulfillment, for the response envelope.
	 */
	public function __construct( ShippingService $shipping, WorkflowService $workflow, FulfillmentDetailService $detail ) {
		$this->shipping = $shipping;
		$this->workflow = $workflow;
		$this->detail   = $detail;
	}

	/**
	 * Registers this controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/fulfillments/(?P<fulfillment_id>\d+)/shipments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_shipments' ),
					'permission_callback' => $this->require_capability( Capabilities::VIEW_QUEUE ),
					'args'                => self::fulfillment_id_arg(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_shipment' ),
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_SHIPMENTS ),
					'args'                => self::fulfillment_id_arg(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/shipments/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_shipment' ),
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_SHIPMENTS ),
					'args'                => array_merge(
						self::id_arg(),
						array(
							'carrier_id'      => array(
								'type'              => 'string',
								'default'           => '',
								'sanitize_callback' => 'sanitize_key',
							),
							'service'         => array(
								'type'              => 'string',
								'default'           => '',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'tracking_number' => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'tracking_url'    => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_url',
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_shipment' ),
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_SHIPMENTS ),
					'args'                => self::id_arg(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/shipments/(?P<id>\d+)/ship',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'ship' ),
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_SHIPMENTS ),
					'args'                => self::id_arg(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/shipments/(?P<id>\d+)/packages',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_package' ),
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_SHIPMENTS ),
					'args'                => self::id_arg(),
				),
			)
		);
	}

	/**
	 * `GET /fulfillments/{id}/shipments`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function list_shipments( WP_REST_Request $request ): WP_REST_Response {
		$rows = $this->shipping->list_for_fulfillment( (int) $request->get_param( 'fulfillment_id' ) );

		return $this->respond( array( 'shipments' => array_map( array( self::class, 'shipment_with_packages_resource' ), $rows ) ) );
	}

	/**
	 * `POST /fulfillments/{id}/shipments`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function create_shipment( WP_REST_Request $request ) {
		$fulfillment_id = (int) $request->get_param( 'fulfillment_id' );
		$outcome        = $this->shipping->create_shipment( $fulfillment_id, self::current_actor() );

		if ( ! $outcome->is_success() ) {
			return self::failure_error( (string) $outcome->failure_code(), (string) $outcome->failure_message() );
		}

		return $this->respond( $this->envelope( 'shipment', self::shipment_resource( $outcome->result() ), $fulfillment_id ), 201 );
	}

	/**
	 * `PATCH /shipments/{id}`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function update_shipment( WP_REST_Request $request ) {
		$outcome = $this->shipping->update_shipment(
			(int) $request->get_param( 'id' ),
			(string) $request->get_param( 'carrier_id' ),
			(string) $request->get_param( 'service' ),
			self::nullable_string( $request->get_param( 'tracking_number' ) ),
			self::nullable_string( $request->get_param( 'tracking_url' ) ),
			self::current_actor()
		);

		if ( ! $outcome->is_success() ) {
			return self::failure_error( (string) $outcome->failure_code(), (string) $outcome->failure_message() );
		}

		return $this->respond( $this->envelope( 'shipment', self::shipment_resource( $outcome->result() ), $outcome->result()->fulfillment_id() ) );
	}

	/**
	 * `DELETE /shipments/{id}`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function delete_shipment( WP_REST_Request $request ) {
		$shipment_id = (int) $request->get_param( 'id' );
		$shipment    = $this->shipping->find_shipment( $shipment_id );

		if ( null === $shipment ) {
			return self::not_found_error( "No shipment exists with id {$shipment_id}." );
		}

		$fulfillment_id = $shipment->fulfillment_id();
		$outcome        = $this->shipping->delete_shipment( $shipment_id, self::current_actor() );

		if ( ! $outcome->is_success() ) {
			return self::failure_error( (string) $outcome->failure_code(), (string) $outcome->failure_message() );
		}

		return $this->respond( $this->envelope( 'shipment', null, $fulfillment_id ) );
	}

	/**
	 * `POST /shipments/{id}/ship`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function ship( WP_REST_Request $request ) {
		$outcome = $this->shipping->ship( (int) $request->get_param( 'id' ), self::current_actor() );

		if ( ! $outcome->is_success() ) {
			return self::failure_error( (string) $outcome->failure_code(), (string) $outcome->failure_message() );
		}

		return $this->respond( $this->envelope( 'shipment', self::shipment_resource( $outcome->result() ), $outcome->result()->fulfillment_id() ) );
	}

	/**
	 * `POST /shipments/{id}/packages`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function add_package( WP_REST_Request $request ) {
		$shipment_id = (int) $request->get_param( 'id' );
		$shipment    = $this->shipping->find_shipment( $shipment_id );

		if ( null === $shipment ) {
			return self::not_found_error( "No shipment exists with id {$shipment_id}." );
		}

		$outcome = $this->shipping->add_package( $shipment_id, self::current_actor() );

		if ( ! $outcome->is_success() ) {
			return self::failure_error( (string) $outcome->failure_code(), (string) $outcome->failure_message() );
		}

		return $this->respond( $this->envelope( 'package', self::package_resource( $outcome->result() ), $shipment->fulfillment_id() ), 201 );
	}

	/**
	 * The `fulfillment_id` path-parameter arg schema.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function fulfillment_id_arg(): array {
		return array(
			'fulfillment_id' => array(
				'type'              => 'integer',
				'required'          => true,
				'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
			),
		);
	}

	/**
	 * The `id` path-parameter arg schema.
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
	 * `''` means "clear this field" everywhere else in this controller,
	 * but {@see \MPCF\Domain\Shipping\TrackingReference} treats `null` and
	 * `''` identically (both are "absent"), so an empty submitted string
	 * is passed straight through — this only exists to satisfy the
	 * nullable-string parameter type, not to change behavior.
	 *
	 * @param mixed $value Raw param value.
	 */
	private static function nullable_string( $value ): ?string {
		return null === $value ? null : (string) $value;
	}

	/**
	 * The shared envelope every mutation in this controller responds
	 * with: the mutated resource (or null, for a delete), the owning
	 * fulfillment, and its fresh candidate transitions.
	 *
	 * @param string                    $key            Either `shipment` or `package`.
	 * @param array<string, mixed>|null $wire_shape     The mutated resource's wire shape, or null.
	 * @param int                       $fulfillment_id Owning fulfillment id.
	 * @return array<string, mixed>
	 */
	private function envelope( string $key, ?array $wire_shape, int $fulfillment_id ): array {
		$view        = $this->detail->get( $fulfillment_id );
		$transitions = $this->workflow->available_transitions( $fulfillment_id, 'current_user_can' );

		return array(
			$key          => $wire_shape,
			'fulfillment' => null !== $view ? self::fulfillment_resource( $view->fulfillment() ) : null,
			'transitions' => self::transitions_resource( $transitions ),
		);
	}

	/**
	 * One `list_for_fulfillment()` row as the wire shape `GET
	 * /fulfillments/{id}/shipments` uses.
	 *
	 * @param array{shipment: \MPCF\Domain\Shipping\Shipment, packages: list<\MPCF\Domain\Shipping\Package>} $row One row.
	 * @return array<string, mixed>
	 */
	private static function shipment_with_packages_resource( array $row ): array {
		$data             = self::shipment_resource( $row['shipment'] );
		$data['packages'] = array_map( array( self::class, 'package_resource' ), $row['packages'] );

		return $data;
	}
}
