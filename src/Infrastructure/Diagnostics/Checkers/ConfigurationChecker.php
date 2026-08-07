<?php
/**
 * Configuration / settings sanity.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Diagnostics\Checkers;

use MPCF\Application\Diagnostics\CheckCategory;
use MPCF\Application\Diagnostics\Checker;
use MPCF\Application\Diagnostics\CheckResult;
use MPCF\Settings;

/**
 * Verifies settings option shape and schema version.
 */
final class ConfigurationChecker implements Checker {

	/**
	 * Stable checker identifier.
	 */
	public function id(): string {
		return 'configuration';
	}

	/**
	 * Checker category for grouping.
	 */
	public function category(): string {
		return CheckCategory::CONFIGURATION;
	}

	/**
	 * Runs diagnostic checks.
	 *
	 * @return list<CheckResult>
	 */
	public function run(): array {
		$results  = array();
		$settings = new Settings();
		$raw      = get_option( Settings::OPTION, null );

		if ( null === $raw ) {
			$results[] = CheckResult::warn(
				'configuration.settings_option',
				CheckCategory::CONFIGURATION,
				'Settings option not stored yet; defaults apply.',
				'',
				'Save plugin settings once, or ignore if defaults are intentional.',
				false
			);
		} elseif ( ! is_array( $raw ) ) {
			$results[] = CheckResult::fail(
				'configuration.settings_option',
				CheckCategory::CONFIGURATION,
				'Settings option is corrupted (not an array).',
				'',
				'Reset settings from the admin Settings screen.',
				false
			);
		} else {
			$results[] = CheckResult::pass( 'configuration.settings_option', CheckCategory::CONFIGURATION, 'Settings option is present.' );
			$ver       = isset( $raw['schema_version'] ) ? (int) $raw['schema_version'] : 0;
			if ( Settings::SCHEMA_VERSION === $ver ) {
				$results[] = CheckResult::pass(
					'configuration.settings_schema',
					CheckCategory::CONFIGURATION,
					sprintf( 'Settings schema version %d is current.', $ver ),
					array( 'schema_version' => $ver )
				);
			} else {
				$results[] = CheckResult::warn(
					'configuration.settings_schema',
					CheckCategory::CONFIGURATION,
					sprintf( 'Settings schema version %d; code expects %d.', $ver, Settings::SCHEMA_VERSION ),
					'',
					'Open and save Settings to re-sanitize, or upgrade the plugin.',
					false,
					array(
						'stored'   => $ver,
						'expected' => Settings::SCHEMA_VERSION,
					)
				);
			}
		}

		// Touch getters to ensure defaults resolve without fatals.
		$settings->get();
		$results[] = CheckResult::pass( 'configuration.settings_readable', CheckCategory::CONFIGURATION, 'Settings getters resolve without error.' );

		return $results;
	}
}
