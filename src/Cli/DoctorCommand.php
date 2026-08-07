<?php
/**
 * `wp mpcf doctor` — read-only operational diagnostics.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Cli;

use MPCF\Application\Diagnostics\CheckStatus;
use MPCF\Application\Diagnostics\DoctorService;
use WP_CLI;

/**
 * Thin WP-CLI adapter over DoctorService.
 */
final class DoctorCommand {

	/**
	 * Builds the command handler.
	 *
	 * @param DoctorService $doctor Doctor façade.
	 */
	public function __construct(
		private DoctorService $doctor
	) {
	}

	/**
	 * Registers the command.
	 */
	public function register(): void {
		WP_CLI::add_command( 'mpcf doctor', array( $this, 'doctor' ) );
	}

	/**
	 * ## OPTIONS
	 *
	 * [--check=<id>]
	 * : Run a single checker id (e.g. schema, storage).
	 *
	 * [--format=<format>]
	 * : table or json. Default: table.
	 *
	 * @param array<int, string>    $args       Positional (unused).
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function doctor( array $args, array $assoc_args ): void {
		unset( $args );
		$check  = isset( $assoc_args['check'] ) ? (string) $assoc_args['check'] : null;
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		$report = $this->doctor->run( $check );

		if ( 'json' === $format ) {
			WP_CLI::print_value( $report->to_array(), array( 'format' => 'json' ) );
		} else {
			$rows = array();
			foreach ( $report->results() as $result ) {
				$rows[] = array(
					'id'       => $result->id(),
					'category' => $result->category(),
					'status'   => $result->status(),
					'summary'  => $result->summary(),
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'category', 'status', 'summary' ) );
			WP_CLI::log(
				sprintf(
					'Summary: pass=%d warn=%d fail=%d',
					$report->pass_count(),
					$report->warn_count(),
					$report->fail_count()
				)
			);
			foreach ( $report->results() as $result ) {
				if ( CheckStatus::PASS === $result->status() ) {
					continue;
				}
				if ( '' !== $result->remediation() ) {
					WP_CLI::log( sprintf( '[%s] %s', $result->id(), $result->remediation() ) );
				}
			}
		}

		if ( $report->has_failures() ) {
			WP_CLI::error( 'Doctor found critical failures.', false );
			exit( 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP-CLI exit.
		}

		if ( $report->has_warnings() ) {
			WP_CLI::warning( 'Doctor completed with warnings.' );
			return;
		}

		WP_CLI::success( 'Doctor completed — all checks passed.' );
	}
}
