<?php
/**
 * Versioned schema migrations.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Database;

/**
 * Runs ordered, explicit SQL migration steps and records the schema version.
 *
 * The version lives in its own option (`mpcf_db_version`), separate from
 * `MPCF\Settings`, so a settings reset can never be mistaken for a schema
 * reset.
 *
 * Migrations run from two places: the activation hook, and an `admin_init`
 * drift check (`MPCF\Plugin::init()`). The second is not redundant — a
 * bind-mount deployment that updates files in place never fires the
 * activation hook, so without the drift check a schema upgrade would
 * silently never happen there (the same lesson the sibling plugins'
 * `Migrator` classes already encode).
 *
 * Every step is idempotent so a partially applied migration can be re-run
 * safely. The step map and target version are constructor-overridable
 * (defaulting to the real ones) purely so integration tests can exercise
 * per-step persistence, drift resume, and idempotency against a disposable
 * fake step map without needing Milestone 1's real tables to exist yet.
 */
final class Migrator {

	/**
	 * Option holding the applied schema version.
	 */
	public const OPTION = 'mpcf_db_version';

	/**
	 * Schema version this build expects. Milestone 0 introduces no tables,
	 * so the target is 0 — Milestone 1 raises it alongside its first step.
	 */
	public const TARGET = 0;

	/**
	 * Test-only step map override.
	 *
	 * @var array<int, callable():void>|null
	 */
	private ?array $steps_override;

	/**
	 * Test-only target-version override.
	 *
	 * @var int|null
	 */
	private ?int $target_override;

	/**
	 * Builds the migrator.
	 *
	 * @param array<int, callable():void>|null $steps_override Overrides {@see steps()}, for tests.
	 * @param int|null                         $target_override Overrides {@see TARGET}, for tests.
	 */
	public function __construct( ?array $steps_override = null, ?int $target_override = null ) {
		$this->steps_override  = $steps_override;
		$this->target_override = $target_override;
	}

	/**
	 * Applies any migration steps newer than the recorded version.
	 *
	 * The version is written after each individual step, so an interrupted
	 * run resumes from the step that failed rather than replaying from zero.
	 * The target version is written unconditionally at the end so the
	 * option always exists and reflects reality, even when there are no
	 * steps to run at all (Milestone 0).
	 */
	public function migrate(): void {
		$current = $this->current_version();

		foreach ( $this->steps() as $version => $step ) {
			if ( $version <= $current ) {
				continue;
			}

			$step();

			update_option( self::OPTION, $version, true );
		}

		update_option( self::OPTION, $this->target(), true );
	}

	/**
	 * Runs migrations only when the recorded version is behind this build's
	 * target.
	 */
	public function maybe_migrate(): void {
		if ( $this->current_version() >= $this->target() ) {
			return;
		}

		$this->migrate();
	}

	/**
	 * Schema version currently applied to the database.
	 */
	public function current_version(): int {
		return (int) get_option( self::OPTION, 0 );
	}

	/**
	 * The version this instance migrates toward.
	 */
	private function target(): int {
		return $this->target_override ?? self::TARGET;
	}

	/**
	 * Ordered migration steps, keyed by the version they produce.
	 *
	 * Empty in Milestone 0 — there is nothing to create yet. Milestone 1
	 * adds `1 => array( $this, 'step_1_initial_tables' )`.
	 *
	 * @return array<int, callable():void>
	 */
	private function steps(): array {
		return $this->steps_override ?? array();
	}
}
