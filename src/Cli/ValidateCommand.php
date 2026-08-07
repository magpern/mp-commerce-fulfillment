<?php
/**
 * `wp mpcf validate <target>` — read-only focused validation.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Cli;

use MPCF\Application\Diagnostics\CheckStatus;
use MPCF\Application\Diagnostics\ValidationService;
use WP_CLI;

/**
 * Thin WP-CLI adapter over ValidationService.
 */
final class ValidateCommand {

	/**
	 * Builds the command handler.
	 *
	 * @param ValidationService $validation Validation façade.
	 */
	public function __construct(
		private ValidationService $validation
	) {
	}

	/**
	 * Registers the command.
	 */
	public function register(): void {
		WP_CLI::add_command( 'mpcf validate', array( $this, 'validate' ) );
	}

	/**
	 * ## OPTIONS
	 *
	 * <target>
	 * : schema|storage|schedules|consistency|fulfillments|waves|analytics
	 *
	 * [--format=<format>]
	 * : table or json. Default: table.
	 *
	 * @param array<int, string>    $args       Positional.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function validate( array $args, array $assoc_args ): void {
		$target = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $target ) {
			WP_CLI::error( 'Usage: wp mpcf validate <' . implode( '|', ValidationService::targets() ) . '>' );
		}

		$format  = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$results = $this->validation->validate( $target );
		$fail    = false;

		if ( 'json' === $format ) {
			WP_CLI::print_value(
				array_map( static fn( $r ) => $r->to_array(), $results ),
				array( 'format' => 'json' )
			);
		} else {
			$rows = array();
			foreach ( $results as $result ) {
				if ( CheckStatus::FAIL === $result->status() ) {
					$fail = true;
				}
				$rows[] = array(
					'id'      => $result->id(),
					'status'  => $result->status(),
					'summary' => $result->summary(),
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'status', 'summary' ) );
		}

		foreach ( $results as $result ) {
			if ( CheckStatus::FAIL === $result->status() ) {
				$fail = true;
			}
		}

		if ( $fail ) {
			WP_CLI::error( 'Validation found failures.', false );
			exit( 1 );
		}

		WP_CLI::success( 'Validation passed.' );
	}
}
