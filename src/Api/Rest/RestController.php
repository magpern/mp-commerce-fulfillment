<?php
/**
 * Contract every REST controller implements.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Api\Rest;

/**
 * Mirrors {@see \MPCF\Vendor\Mpds\PageShell\Page}'s role for admin screens:
 * a common contract {@see RestApi} loops over to register every
 * controller's routes, without any controller needing to know about its
 * siblings.
 */
interface RestController {

	/**
	 * Registers this controller's routes. Called from a `rest_api_init`
	 * callback — never earlier, which is the one timing rule
	 * `register_rest_route()` itself imposes.
	 */
	public function register_routes(): void;
}
