<?php
/**
 * WordPress Site Health adapter over CheckerRegistry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\SiteHealth;

use MPCF\Application\Diagnostics\CheckStatus;
use MPCF\Application\Diagnostics\CheckerRegistry;
use MPCF\Application\Diagnostics\CheckResult;

/**
 * Presentation only — never mutates; never reimplements check logic.
 */
final class SiteHealthRegistrar {

	/**
	 * Transient cache TTL for expensive aggregate (seconds).
	 */
	private const CACHE_TTL = 300;

	/**
	 * Builds the Site Health adapter.
	 *
	 * @param CheckerRegistry $registry Shared checker registry.
	 */
	public function __construct(
		private CheckerRegistry $registry
	) {
	}

	/**
	 * Hooks Site Health filters.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'register_tests' ) );
	}

	/**
	 * Adds the MPCF aggregate test to Site Health.
	 *
	 * @param array<string, mixed> $tests Existing tests.
	 * @return array<string, mixed>
	 */
	public function register_tests( array $tests ): array {
		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}

		$tests['direct']['mpcf_operational'] = array(
			'label' => __( 'MP Commerce Fulfillment', 'mp-commerce-fulfillment' ),
			'test'  => array( $this, 'run_aggregate_test' ),
		);

		return $tests;
	}

	/**
	 * Aggregates checker results into one Site Health test (cached).
	 *
	 * @return array<string, mixed>
	 */
	public function run_aggregate_test(): array {
		$cached = get_transient( 'mpcf_site_health_ops' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$results = $this->registry->run_all();
		$fail    = array();
		$warn    = array();

		foreach ( $results as $result ) {
			if ( CheckStatus::FAIL === $result->status() ) {
				$fail[] = $result;
			} elseif ( CheckStatus::WARN === $result->status() ) {
				$warn[] = $result;
			}
		}

		if ( array() !== $fail ) {
			$payload = $this->build(
				'critical',
				__( 'MPCF has critical operational issues', 'mp-commerce-fulfillment' ),
				$this->describe( $fail ),
				$fail
			);
		} elseif ( array() !== $warn ) {
			$payload = $this->build(
				'recommended',
				__( 'MPCF reports operational warnings', 'mp-commerce-fulfillment' ),
				$this->describe( $warn ),
				$warn
			);
		} else {
			$payload = $this->build(
				'good',
				__( 'MPCF operational checks passed', 'mp-commerce-fulfillment' ),
				__( 'Environment, schema, storage, and schedules look healthy. Run `wp mpcf doctor` for detail.', 'mp-commerce-fulfillment' ),
				array()
			);
		}

		set_transient( 'mpcf_site_health_ops', $payload, self::CACHE_TTL );

		return $payload;
	}

	/**
	 * Builds HTML description from failing/warning results.
	 *
	 * @param array $results Results to summarize.
	 */
	private function describe( array $results ): string {
		$lines = array();
		foreach ( array_slice( $results, 0, 8 ) as $result ) {
			$lines[] = esc_html( $result->summary() );
			if ( '' !== $result->remediation() ) {
				$lines[] = esc_html( $result->remediation() );
			}
		}

		$extra = '';
		if ( count( $results ) > 8 ) {
			$extra = '<p>' . esc_html(
				sprintf(
					/* translators: %d: additional finding count */
					__( '…and %d more. Use wp mpcf doctor for the full report.', 'mp-commerce-fulfillment' ),
					count( $results ) - 8
				)
			) . '</p>';
		}

		return '<p>' . implode( '</p><p>', $lines ) . '</p>' . $extra;
	}

	/**
	 * Builds one Site Health test payload.
	 *
	 * @param string $status      Site Health status slug.
	 * @param string $label       Test label.
	 * @param string $description HTML description.
	 * @param array  $results     Findings (unused; kept for call-site clarity).
	 * @return array<string, mixed>
	 */
	private function build( string $status, string $label, string $description, array $results ): array {
		unset( $results );

		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'MPCF', 'mp-commerce-fulfillment' ),
				'color' => 'critical' === $status ? 'red' : ( 'recommended' === $status ? 'orange' : 'green' ),
			),
			'description' => $description,
			'actions'     => '',
			'test'        => 'mpcf_operational',
		);
	}
}
