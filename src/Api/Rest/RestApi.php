<?php
/**
 * Registers every REST controller's routes at the right time.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Api\Rest;

/**
 * `register_rest_route()` may only be called from a `rest_api_init`
 * callback — this class is the one place that timing rule is honored, so
 * no controller needs to know it itself.
 */
final class RestApi {

	/**
	 * Controllers to register, in registration order.
	 *
	 * @var list<RestController>
	 */
	private array $controllers;

	/**
	 * Builds the registrar.
	 *
	 * @param array<int, RestController> $controllers Controllers to register, in registration order.
	 */
	public function __construct( array $controllers ) {
		$this->controllers = $controllers;
	}

	/**
	 * Hooks `rest_api_init` to register every controller's routes.
	 */
	public function register(): void {
		add_action(
			'rest_api_init',
			function (): void {
				foreach ( $this->controllers as $controller ) {
					$controller->register_routes();
				}
			}
		);
	}
}
