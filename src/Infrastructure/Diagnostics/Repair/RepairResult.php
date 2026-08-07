<?php
/**
 * Result of a repair attempt (dry-run or applied).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Diagnostics\Repair;

/**
 * Structured repair outcome for CLI.
 */
final class RepairResult {

	/**
	 * Builds a repair result DTO.
	 *
	 * @param string               $target   Repair target id.
	 * @param bool                 $dry_run  Whether mutation was skipped.
	 * @param bool                 $applied  Whether mutation ran.
	 * @param string               $summary  Human summary.
	 * @param array<string, mixed> $before   Before snapshot.
	 * @param array<string, mixed> $after    After snapshot.
	 * @param array                $changes  Intended or applied change lines.
	 */
	public function __construct(
		private string $target,
		private bool $dry_run,
		private bool $applied,
		private string $summary,
		private array $before = array(),
		private array $after = array(),
		private array $changes = array()
	) {
	}

	/**
	 * Returns the repair target id.
	 */
	public function target(): string {
		return $this->target;
	}

	/**
	 * Whether the run was dry-run only.
	 */
	public function dry_run(): bool {
		return $this->dry_run;
	}

	/**
	 * Whether mutations were applied.
	 */
	public function applied(): bool {
		return $this->applied;
	}

	/**
	 * Returns the human summary.
	 */
	public function summary(): string {
		return $this->summary;
	}

	/**
	 * Before snapshot.
	 *
	 * @return array<string, mixed>
	 */
	public function before(): array {
		return $this->before;
	}

	/**
	 * After snapshot.
	 *
	 * @return array<string, mixed>
	 */
	public function after(): array {
		return $this->after;
	}

	/**
	 * Intended or applied change lines.
	 *
	 * @return list<string>
	 */
	public function changes(): array {
		return $this->changes;
	}

	/**
	 * Serializes the repair outcome.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'target'  => $this->target,
			'dry_run' => $this->dry_run,
			'applied' => $this->applied,
			'summary' => $this->summary,
			'before'  => $this->before,
			'after'   => $this->after,
			'changes' => $this->changes,
		);
	}
}
