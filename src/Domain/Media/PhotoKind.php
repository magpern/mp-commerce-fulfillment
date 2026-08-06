<?php
/**
 * Allow-listed package photography kinds.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Media;

use InvalidArgumentException;

/**
 * Part VIII: evidence kinds. Contents photos never satisfy
 * `photo_required`; only {@see self::PACKAGE} does.
 */
final class PhotoKind {

	/**
	 * Open-box / contents evidence.
	 */
	public const CONTENTS = 'contents';

	/**
	 * Final sealed package evidence.
	 */
	public const PACKAGE = 'package';

	/**
	 * Prevents instantiation.
	 */
	private function __construct() {
	}

	/**
	 * Whether the value is an allow-listed kind.
	 *
	 * @param string $kind Candidate kind.
	 */
	public static function is_valid( string $kind ): bool {
		return in_array( $kind, array( self::CONTENTS, self::PACKAGE ), true );
	}

	/**
	 * Asserts the value is allow-listed.
	 *
	 * @param string $kind Candidate kind.
	 * @throws InvalidArgumentException When the kind is not allow-listed.
	 */
	public static function assert_valid( string $kind ): void {
		if ( ! self::is_valid( $kind ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid photo kind "%s".', $kind ) );
		}
	}
}
