<?php
/**
 * Registry of diagnostic checkers — single source of truth for doctor + Site Health.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Diagnostics;

/**
 * Deterministic registration order; no duplicate checker ids.
 */
final class CheckerRegistry {

	/**
	 * Registered checkers keyed by id.
	 *
	 * @var array<string, Checker>
	 */
	private array $checkers = array();

	/**
	 * Registers a checker. Later registration with the same id replaces.
	 *
	 * @param Checker $checker Checker instance.
	 */
	public function register( Checker $checker ): void {
		$this->checkers[ $checker->id() ] = $checker;
	}

	/**
	 * Returns all registered checkers.
	 *
	 * @return list<Checker>
	 */
	public function all(): array {
		return array_values( $this->checkers );
	}

	/**
	 * Returns one checker by id.
	 *
	 * @param string $id Checker id.
	 */
	public function get( string $id ): ?Checker {
		return $this->checkers[ $id ] ?? null;
	}

	/**
	 * Runs every checker; results sorted by category order then id.
	 *
	 * @return list<CheckResult>
	 */
	public function run_all(): array {
		$results = array();
		foreach ( $this->all() as $checker ) {
			foreach ( $checker->run() as $result ) {
				$results[] = $result;
			}
		}

		return $this->sort( $results );
	}

	/**
	 * Runs a single checker by id.
	 *
	 * @param string $id Checker id.
	 * @return list<CheckResult>
	 */
	public function run_one( string $id ): array {
		$checker = $this->get( $id );
		if ( null === $checker ) {
			return array();
		}

		return $this->sort( $checker->run() );
	}

	/**
	 * Sorts results by category order then id.
	 *
	 * @param array $results Results to sort.
	 * @return list<CheckResult>
	 */
	private function sort( array $results ): array {
		$order = array_flip( CheckCategory::all() );
		usort(
			$results,
			static function ( CheckResult $a, CheckResult $b ) use ( $order ): int {
				$ca = $order[ $a->category() ] ?? 99;
				$cb = $order[ $b->category() ] ?? 99;
				if ( $ca !== $cb ) {
					return $ca <=> $cb;
				}

				return strcmp( $a->id(), $b->id() );
			}
		);

		return $results;
	}
}
