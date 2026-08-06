<?php
/**
 * Wave lifecycle states.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Wave;

/**
 * Architecture Plan Part X.2 — wave lifecycle states.
 */
final class WaveState {

	public const DRAFT = 'draft';

	public const ACTIVE = 'active';

	public const PAUSED = 'paused';

	public const COMPLETED = 'completed';

	public const ABANDONED = 'abandoned';

	/**
	 * Every valid state key.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::DRAFT,
			self::ACTIVE,
			self::PAUSED,
			self::COMPLETED,
			self::ABANDONED,
		);
	}

	/**
	 * Whether `$state` is a known wave state.
	 *
	 * @param string $state Candidate state.
	 */
	public static function is_valid( string $state ): bool {
		return in_array( $state, self::all(), true );
	}

	/**
	 * Whether the wave still holds exclusive membership locks.
	 *
	 * @param string $state Wave state.
	 */
	public static function is_open( string $state ): bool {
		return in_array( $state, array( self::DRAFT, self::ACTIVE, self::PAUSED ), true );
	}

	/**
	 * Whether `$from` may transition to `$to`.
	 *
	 * @param string $from Current state.
	 * @param string $to   Target state.
	 */
	public static function can_transition( string $from, string $to ): bool {
		$map = array(
			self::DRAFT     => array( self::ACTIVE, self::ABANDONED ),
			self::ACTIVE    => array( self::PAUSED, self::COMPLETED, self::ABANDONED ),
			self::PAUSED    => array( self::ACTIVE, self::ABANDONED ),
			self::COMPLETED => array(),
			self::ABANDONED => array(),
		);

		return in_array( $to, $map[ $from ] ?? array(), true );
	}
}
