<?php
/**
 * Re-entrancy guard for bridge-initiated order platform writes.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Woo;

/**
 * An int depth counter (the same pattern used by this house's sibling
 * plugins' currency/price-lock guards): while a bridge-initiated write to
 * the order platform is in flight, every other Woo hook handler that could
 * otherwise re-enter intake or the inbound observers checks
 * {@see is_active()} and bails out early. This is what makes a bridge write
 * safe even if it happens to also fire a hook this plugin listens to for an
 * unrelated reason (e.g. the outbound mapping is configured to a status
 * other than the default, and that status also has an intake-relevant
 * hook) — the depth counter, not knowledge of every possible hook overlap,
 * is what makes this safe.
 */
final class BridgeReentrancyGuard {

	/**
	 * Current re-entrancy depth. Never negative: {@see run()} always pairs
	 * an increment with a decrement, even when `$callback` throws.
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/**
	 * Runs `$callback` with the guard held, so anything it triggers
	 * (directly or via a platform hook) can observe {@see is_active()}.
	 *
	 * @param callable():void $callback The bridge-initiated write to run.
	 */
	public static function run( callable $callback ): void {
		++self::$depth;

		try {
			$callback();
		} finally {
			--self::$depth;
		}
	}

	/**
	 * Whether a bridge-initiated write is currently in flight.
	 */
	public static function is_active(): bool {
		return self::$depth > 0;
	}
}
