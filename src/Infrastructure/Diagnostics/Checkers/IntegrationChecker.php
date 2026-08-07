<?php
/**
 * Integration surface checks (REST / AS).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Diagnostics\Checkers;

use MPCF\Application\Diagnostics\CheckCategory;
use MPCF\Application\Diagnostics\Checker;
use MPCF\Application\Diagnostics\CheckResult;

/**
 * Verifies REST routes are registered when the REST server is available.
 */
final class IntegrationChecker implements Checker {

	/**
	 * Stable checker identifier.
	 */
	public function id(): string {
		return 'integration';
	}

	/**
	 * Checker category for grouping.
	 */
	public function category(): string {
		return CheckCategory::INTEGRATION;
	}

	/**
	 * Runs diagnostic checks.
	 *
	 * @return list<CheckResult>
	 */
	public function run(): array {
		$results = array();

		if ( ! function_exists( 'rest_get_server' ) ) {
			$results[] = CheckResult::warn( 'integration.rest', CheckCategory::INTEGRATION, 'REST server API unavailable in this context.' );
			return $results;
		}

		$routes = rest_get_server()->get_routes();
		$needed = array(
			'/mpcf/v1/fulfillments',
			'/mpcf/v1/analytics/overview',
		);
		foreach ( $needed as $route ) {
			$found = false;
			foreach ( array_keys( $routes ) as $r ) {
				if ( str_starts_with( (string) $r, $route ) ) {
					$found = true;
					break;
				}
			}
			if ( $found ) {
				$results[] = CheckResult::pass( 'integration.rest.' . md5( $route ), CheckCategory::INTEGRATION, sprintf( 'REST route family %s registered.', $route ) );
			} else {
				$results[] = CheckResult::fail(
					'integration.rest.' . md5( $route ),
					CheckCategory::INTEGRATION,
					sprintf( 'Expected REST route family %s is not registered.', $route ),
					'',
					'Confirm the plugin is active and rest_api_init ran.',
					false,
					array( 'route' => $route )
				);
			}
		}

		$mailer    = function_exists( 'wp_mail' );
		$results[] = $mailer
			? CheckResult::pass( 'integration.wp_mail', CheckCategory::INTEGRATION, 'wp_mail() is available.' )
			: CheckResult::warn( 'integration.wp_mail', CheckCategory::INTEGRATION, 'wp_mail() unavailable.', '', 'Notifications cannot send.' );

		return $results;
	}
}
