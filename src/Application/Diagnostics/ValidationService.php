<?php
/**
 * Read-only focused validators (M10-B).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Diagnostics;

/**
 * Runs subsets of CheckerRegistry by category / id. Never mutates.
 */
final class ValidationService {

	/**
	 * Builds the validation service.
	 *
	 * @param CheckerRegistry $registry Shared registry.
	 */
	public function __construct(
		private CheckerRegistry $registry
	) {
	}

	/**
	 * Supported validation targets.
	 *
	 * @return list<string>
	 */
	public static function targets(): array {
		return array( 'schema', 'storage', 'schedules', 'consistency', 'fulfillments', 'waves', 'analytics' );
	}

	/**
	 * Runs a named validation target.
	 *
	 * @param string $target One of {@see targets()}.
	 * @return list<CheckResult>
	 */
	public function validate( string $target ): array {
		$target = strtolower( $target );

		return match ( $target ) {
			'schema'       => $this->registry->run_one( 'schema' ),
			'storage'      => $this->registry->run_one( 'storage' ),
			'schedules'    => $this->registry->run_one( 'schedule' ),
			'consistency', 'fulfillments', 'waves' => $this->filter_consistency( $target ),
			'analytics'    => $this->filter_by_id_prefix( 'capacity.analytics' ),
			default        => array(
				CheckResult::fail(
					'validate.unknown_target',
					CheckCategory::CONFIGURATION,
					sprintf( 'Unknown validation target: %s', $target ),
					'Supported: ' . implode( ', ', self::targets() ),
					'Pass a supported target.',
					false
				),
			),
		};
	}

	/**
	 * Filters consistency checker output by target.
	 *
	 * @param string $target Validation target name.
	 * @return list<CheckResult>
	 */
	private function filter_consistency( string $target ): array {
		$all = $this->registry->run_one( 'consistency' );
		if ( 'consistency' === $target || 'fulfillments' === $target ) {
			if ( 'fulfillments' === $target ) {
				return array_values(
					array_filter(
						$all,
						static fn( CheckResult $r ): bool => ! str_contains( $r->id(), 'wave' )
					)
				);
			}

			return $all;
		}

		return array_values(
			array_filter(
				$all,
				static fn( CheckResult $r ): bool => str_contains( $r->id(), 'wave' )
			)
		);
	}

	/**
	 * Collects results whose ids start with a prefix.
	 *
	 * @param string $prefix Id prefix.
	 * @return list<CheckResult>
	 */
	private function filter_by_id_prefix( string $prefix ): array {
		$out = array();
		foreach ( $this->registry->run_all() as $result ) {
			if ( str_starts_with( $result->id(), $prefix ) ) {
				$out[] = $result;
			}
		}

		return $out;
	}
}
