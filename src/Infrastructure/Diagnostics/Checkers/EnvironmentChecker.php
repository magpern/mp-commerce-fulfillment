<?php
/**
 * Environment checks (PHP / WordPress / commerce platform / extensions).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Diagnostics\Checkers;

use MPCF\Application\Diagnostics\CheckCategory;
use MPCF\Application\Diagnostics\Checker;
use MPCF\Application\Diagnostics\CheckResult;

/**
 * Read-only environment probes.
 */
final class EnvironmentChecker implements Checker {

	/**
	 * Stable checker identifier.
	 */
	public function id(): string {
		return 'environment';
	}

	/**
	 * Checker category for grouping.
	 */
	public function category(): string {
		return CheckCategory::ENVIRONMENT;
	}

	/**
	 * Runs diagnostic checks.
	 *
	 * @return list<CheckResult>
	 */
	public function run(): array {
		$results = array();

		$php = PHP_VERSION;
		if ( version_compare( $php, '8.1.0', '>=' ) ) {
			$results[] = CheckResult::pass( 'environment.php_version', CheckCategory::ENVIRONMENT, sprintf( 'PHP %s meets floor 8.1.', $php ), array( 'php' => $php ) );
		} else {
			$results[] = CheckResult::fail(
				'environment.php_version',
				CheckCategory::ENVIRONMENT,
				sprintf( 'PHP %s is below required 8.1.', $php ),
				'Upgrade PHP to 8.1 or newer.',
				'Upgrade the PHP runtime to >= 8.1.',
				false,
				array( 'php' => $php )
			);
		}

		$wp = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '';
		if ( '' !== $wp && version_compare( $wp, '6.5', '>=' ) ) {
			$results[] = CheckResult::pass( 'environment.wp_version', CheckCategory::ENVIRONMENT, sprintf( 'WordPress %s meets floor 6.5.', $wp ), array( 'wp' => $wp ) );
		} elseif ( '' === $wp ) {
			$results[] = CheckResult::warn( 'environment.wp_version', CheckCategory::ENVIRONMENT, 'WordPress version could not be determined.', '', 'Confirm WordPress is loaded.', false );
		} else {
			$results[] = CheckResult::fail(
				'environment.wp_version',
				CheckCategory::ENVIRONMENT,
				sprintf( 'WordPress %s is below required 6.5.', $wp ),
				'',
				'Upgrade WordPress to >= 6.5.',
				false,
				array( 'wp' => $wp )
			);
		}

		// Detect via public helper — avoids naming platform class symbols outside src/Woo/ (I8).
		if ( function_exists( 'wc_get_orders' ) ) {
			$results[] = CheckResult::pass( 'environment.commerce_platform', CheckCategory::ENVIRONMENT, 'Required commerce platform helpers are available.' );
		} else {
			$results[] = CheckResult::fail(
				'environment.commerce_platform',
				CheckCategory::ENVIRONMENT,
				'Required commerce platform is not available.',
				'',
				'Activate the required commerce plugin.',
				false
			);
		}

		foreach ( array( 'mysqli', 'json', 'mbstring' ) as $ext ) {
			if ( extension_loaded( $ext ) ) {
				$results[] = CheckResult::pass( 'environment.ext.' . $ext, CheckCategory::ENVIRONMENT, sprintf( 'PHP extension %s loaded.', $ext ) );
			} else {
				$results[] = CheckResult::fail(
					'environment.ext.' . $ext,
					CheckCategory::ENVIRONMENT,
					sprintf( 'PHP extension %s is missing.', $ext ),
					'',
					sprintf( 'Enable the %s PHP extension.', $ext ),
					false
				);
			}
		}

		return $results;
	}
}
