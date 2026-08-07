<?php
/**
 * `wp mpcf analytics backfill|rebuild` — UTC only.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Cli;

use MPCF\Application\Analytics\AnalyticsService;
use WP_CLI;

/**
 * Thin WP-CLI adapter. REBUILD rewrites historical rows; backfill uses ROLLUP.
 */
final class AnalyticsCommand {

	/**
	 * Analytics façade.
	 *
	 * @var AnalyticsService
	 */
	private AnalyticsService $analytics;

	/**
	 * Builds the command.
	 *
	 * @param AnalyticsService $analytics Analytics façade.
	 */
	public function __construct( AnalyticsService $analytics ) {
		$this->analytics = $analytics;
	}

	/**
	 * Registers WP-CLI subcommands.
	 */
	public function register(): void {
		WP_CLI::add_command( 'mpcf analytics backfill', array( $this, 'backfill' ) );
		WP_CLI::add_command( 'mpcf analytics rebuild', array( $this, 'rebuild' ) );
	}

	/**
	 * ## OPTIONS
	 *
	 * --from=<yyyy-mm-dd>
	 * : UTC start date (inclusive).
	 *
	 * [--to=<yyyy-mm-dd>]
	 * : UTC end date (inclusive). Defaults to yesterday UTC.
	 *
	 * [--dry-run]
	 * : Report what would run without writing.
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative flags.
	 */
	public function backfill( array $args, array $assoc_args ): void {
		unset( $args );
		$from = isset( $assoc_args['from'] ) ? (string) $assoc_args['from'] : '';
		if ( '' === $from ) {
			WP_CLI::error( 'Required: --from=YYYY-MM-DD (UTC).' );
		}
		$to      = isset( $assoc_args['to'] ) ? (string) $assoc_args['to'] : null;
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( $dry_run ) {
			WP_CLI::log( sprintf( 'Dry-run ROLLUP from %s to %s (UTC).', $from, $to ?? 'yesterday' ) );
			WP_CLI::success( 'Dry-run complete (no writes).' );
			return;
		}

		$result = $this->run_backfill( $from, $to );
		WP_CLI::log( 'Written: ' . $result['written'] );
		WP_CLI::log( 'Unchanged (current version): ' . $result['unchanged'] );
		WP_CLI::log( 'Skipped today: ' . $result['skipped_today'] );
		WP_CLI::success( 'Analytics backfill complete.' );
	}

	/**
	 * ## OPTIONS
	 *
	 * --from=<yyyy-mm-dd>
	 * : UTC start date (inclusive).
	 *
	 * --to=<yyyy-mm-dd>
	 * : UTC end date (inclusive).
	 *
	 * [--dry-run]
	 * : Report without writing.
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative flags.
	 */
	public function rebuild( array $args, array $assoc_args ): void {
		unset( $args );
		$from = isset( $assoc_args['from'] ) ? (string) $assoc_args['from'] : '';
		$to   = isset( $assoc_args['to'] ) ? (string) $assoc_args['to'] : '';
		if ( '' === $from || '' === $to ) {
			WP_CLI::error( 'Required: --from=YYYY-MM-DD and --to=YYYY-MM-DD (UTC).' );
		}
		if ( isset( $assoc_args['dry-run'] ) ) {
			WP_CLI::log( sprintf( 'Dry-run REBUILD from %s to %s (UTC).', $from, $to ) );
			WP_CLI::success( 'Dry-run complete (no writes).' );
			return;
		}

		$result = $this->run_rebuild( $from, $to );
		WP_CLI::log( 'Rebuilt: ' . $result['rebuilt'] );
		WP_CLI::log( 'Obsolete rows remaining: ' . $result['obsolete_remaining'] );
		WP_CLI::success( 'Analytics rebuild complete.' );
	}

	/**
	 * Runs ROLLUP for a closed-day range.
	 *
	 * @param string      $from Inclusive start day key.
	 * @param string|null $to   Inclusive end day key (null = yesterday).
	 * @return array{written: int, unchanged: int, skipped_today: int}
	 */
	public function run_backfill( string $from, ?string $to ): array {
		return $this->analytics->rollup_range( $from, $to );
	}

	/**
	 * Runs REBUILD for a closed-day range.
	 *
	 * @param string $from Inclusive start day key.
	 * @param string $to   Inclusive end day key.
	 * @return array{rebuilt: int, obsolete_remaining: int}
	 */
	public function run_rebuild( string $from, string $to ): array {
		return $this->analytics->rebuild_range( $from, $to );
	}
}
