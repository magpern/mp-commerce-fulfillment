<?php
/**
 * `wp mpcf repair <target>` — bounded, audited repairs.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Cli;

use MPCF\Infrastructure\Diagnostics\Repair\CapabilitiesRepairService;
use MPCF\Infrastructure\Diagnostics\Repair\ScheduleRepairService;
use MPCF\Infrastructure\Diagnostics\Repair\SchemaRepairService;
use MPCF\Infrastructure\Diagnostics\Repair\StorageDirsRepairService;
use WP_CLI;

/**
 * Thin WP-CLI adapter. Mutations require --yes.
 */
final class RepairCommand {

	/**
	 * Builds the command handler.
	 *
	 * @param ScheduleRepairService     $schedules     Schedule repair.
	 * @param StorageDirsRepairService  $storage       Storage dirs repair.
	 * @param SchemaRepairService       $schema        Schema repair.
	 * @param CapabilitiesRepairService $capabilities  Capabilities repair.
	 */
	public function __construct(
		private ScheduleRepairService $schedules,
		private StorageDirsRepairService $storage,
		private SchemaRepairService $schema,
		private CapabilitiesRepairService $capabilities
	) {
	}

	/**
	 * Registers the command.
	 */
	public function register(): void {
		WP_CLI::add_command( 'mpcf repair', array( $this, 'repair' ) );
	}

	/**
	 * ## OPTIONS
	 *
	 * <target>
	 * : schedules|storage-dirs|schema|capabilities
	 *
	 * [--yes]
	 * : Apply mutation. Without this flag, dry-run only.
	 *
	 * [--format=<format>]
	 * : table or json. Default: table.
	 *
	 * @param array<int, string>    $args       Positional.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function repair( array $args, array $assoc_args ): void {
		$target = isset( $args[0] ) ? (string) $args[0] : '';
		$yes    = isset( $assoc_args['yes'] );
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		$result = match ( $target ) {
			'schedules'     => $this->schedules->repair( $yes ),
			'storage-dirs'  => $this->storage->repair( $yes ),
			'schema'        => $this->schema->repair( $yes ),
			'capabilities'  => $this->capabilities->repair( $yes ),
			default         => null,
		};

		if ( null === $result ) {
			WP_CLI::error( 'Usage: wp mpcf repair <schedules|storage-dirs|schema|capabilities> [--yes]' );
		}

		if ( 'json' === $format ) {
			WP_CLI::print_value( $result->to_array(), array( 'format' => 'json' ) );
		} else {
			WP_CLI::log( $result->summary() );
			foreach ( $result->changes() as $line ) {
				WP_CLI::log( ' - ' . $line );
			}
		}

		if ( ! $yes && array() !== $result->changes() ) {
			WP_CLI::warning( 'Dry-run only. Re-run with --yes to apply.' );
			return;
		}

		WP_CLI::success( $result->applied() || array() === $result->changes() ? 'Repair complete.' : 'Nothing applied.' );
	}
}
