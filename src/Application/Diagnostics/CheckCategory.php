<?php
/**
 * Diagnostic failure / check category (Part XII).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Diagnostics;

/**
 * Frozen failure classes for doctor / Site Health.
 */
final class CheckCategory {

	public const ENVIRONMENT   = 'environment';
	public const CONFIGURATION = 'configuration';
	public const PERMISSIONS   = 'permissions';
	public const SCHEMA        = 'schema';
	public const CONSISTENCY   = 'consistency';
	public const STORAGE       = 'storage';
	public const SCHEDULE      = 'schedule';
	public const INTEGRATION   = 'integration';
	public const CAPACITY      = 'capacity';

	/**
	 * Every approved category, stable order.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::ENVIRONMENT,
			self::CONFIGURATION,
			self::PERMISSIONS,
			self::SCHEMA,
			self::CONSISTENCY,
			self::STORAGE,
			self::SCHEDULE,
			self::INTEGRATION,
			self::CAPACITY,
		);
	}
}
