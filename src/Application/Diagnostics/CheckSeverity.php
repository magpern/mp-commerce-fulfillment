<?php
/**
 * Presentation severity mapped from status.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Diagnostics;

/**
 * Site Health / CLI severity labels.
 */
final class CheckSeverity {

	public const INFO     = 'info';
	public const WARNING  = 'warning';
	public const CRITICAL = 'critical';

	/**
	 * Maps status → severity.
	 *
	 * @param string $status CheckStatus::* value.
	 */
	public static function from_status( string $status ): string {
		return match ( $status ) {
			CheckStatus::FAIL => self::CRITICAL,
			CheckStatus::WARN => self::WARNING,
			default           => self::INFO,
		};
	}
}
