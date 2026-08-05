<?php
/**
 * REST controller for the bundled carrier registry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Api\Rest;

use MPCF\Capabilities;
use MPCF\Domain\CarrierRegistry;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET /carriers` — reuses the {@see CarrierRegistry} port exactly as the
 * workspace's shipment panel will (invariant I11); no controller ever
 * names {@see \MPCF\Infrastructure\Carriers\BundledCarrierRegistry}
 * directly. Response is additive: `id`/`label` remain, plus template and
 * validation metadata for future UI (M5-A).
 */
final class CarriersController extends AbstractRestController {

	/**
	 * Carrier data.
	 *
	 * @var CarrierRegistry
	 */
	private CarrierRegistry $carriers;

	/**
	 * Builds the controller.
	 *
	 * @param CarrierRegistry $carriers Carrier data.
	 */
	public function __construct( CarrierRegistry $carriers ) {
		$this->carriers = $carriers;
	}

	/**
	 * Registers this controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/carriers',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_carriers' ),
					'permission_callback' => $this->require_capability( Capabilities::VIEW_QUEUE ),
				),
			)
		);
	}

	/**
	 * `GET /carriers`.
	 */
	public function list_carriers(): WP_REST_Response {
		return $this->respond( array( 'carriers' => $this->carriers->all() ) );
	}
}
