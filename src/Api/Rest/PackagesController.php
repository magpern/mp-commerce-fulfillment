<?php
/**
 * REST controller for packages.
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
use WP_REST_Server;

/**
 * `PATCH|DELETE /packages/{id}` — reuses {@see ShippingService} exactly as
 * {@see ShipmentsController} does, including the same envelope shape
 * (mutated resource + owning fulfillment + fresh candidate transitions).
 */
final class PackagesController extends AbstractRestController {

	/**
	 * Package mutations and reads.
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
	 * @param ShippingService          $shipping Package mutations and reads.
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
			'/packages/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_package' ),
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_SHIPMENTS ),
					'args'                => array(
						'id'              => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
						),
						'weight_grams'    => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'length_mm'       => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'width_mm'        => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'height_mm'       => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'tracking_number' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_package' ),
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_SHIPMENTS ),
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
	}

	/**
	 * `PATCH /packages/{id}`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function update_package( WP_REST_Request $request ) {
		$package_id = (int) $request->get_param( 'id' );

		$outcome = $this->shipping->update_package(
			$package_id,
			self::nullable_int( $request->get_param( 'weight_grams' ) ),
			self::nullable_int( $request->get_param( 'length_mm' ) ),
			self::nullable_int( $request->get_param( 'width_mm' ) ),
			self::nullable_int( $request->get_param( 'height_mm' ) ),
			self::nullable_string( $request->get_param( 'tracking_number' ) ),
			self::current_actor()
		);

		if ( ! $outcome->is_success() ) {
			return self::failure_error( (string) $outcome->failure_code(), (string) $outcome->failure_message() );
		}

		$fulfillment_id = $this->resolve_fulfillment_id( $outcome->result()->shipment_id() );

		return $this->respond( $this->envelope( self::package_resource( $outcome->result() ), $fulfillment_id ) );
	}

	/**
	 * `DELETE /packages/{id}`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function remove_package( WP_REST_Request $request ) {
		$package_id = (int) $request->get_param( 'id' );
		$package    = $this->shipping->find_package( $package_id );

		if ( null === $package ) {
			return self::not_found_error( "No package exists with id {$package_id}." );
		}

		$fulfillment_id = $this->resolve_fulfillment_id( $package->shipment_id() );
		$outcome        = $this->shipping->remove_package( $package_id, self::current_actor() );

		if ( ! $outcome->is_success() ) {
			return self::failure_error( (string) $outcome->failure_code(), (string) $outcome->failure_message() );
		}

		return $this->respond( $this->envelope( null, $fulfillment_id ) );
	}

	/**
	 * A package only knows its shipment's id; this resolves the
	 * fulfillment that shipment belongs to.
	 *
	 * @param int $shipment_id Shipment id.
	 */
	private function resolve_fulfillment_id( int $shipment_id ): ?int {
		$shipment = $this->shipping->find_shipment( $shipment_id );

		return null !== $shipment ? $shipment->fulfillment_id() : null;
	}

	/**
	 * Casts a possibly-absent param to `int`, leaving `null` as `null`.
	 *
	 * @param mixed $value Raw param value.
	 */
	private static function nullable_int( $value ): ?int {
		return null === $value ? null : (int) $value;
	}

	/**
	 * Casts a possibly-absent param to `string`, leaving `null` as `null`.
	 *
	 * @param mixed $value Raw param value.
	 */
	private static function nullable_string( $value ): ?string {
		return null === $value ? null : (string) $value;
	}

	/**
	 * The shared envelope every mutation in this controller responds
	 * with: the mutated package (or null, for a delete), the owning
	 * fulfillment, and its fresh candidate transitions.
	 *
	 * @param array<string, mixed>|null $package        The mutated package's wire shape, or null.
	 * @param int|null                  $fulfillment_id Owning fulfillment id, or null if it could not be resolved.
	 * @return array<string, mixed>
	 */
	private function envelope( ?array $package, ?int $fulfillment_id ): array {
		$view        = null !== $fulfillment_id ? $this->detail->get( $fulfillment_id ) : null;
		$transitions = null !== $fulfillment_id ? $this->workflow->available_transitions( $fulfillment_id, 'current_user_can' ) : array();

		return array(
			'package'     => $package,
			'fulfillment' => null !== $view ? self::fulfillment_resource( $view->fulfillment() ) : null,
			'transitions' => self::transitions_resource( $transitions ),
		);
	}
}
