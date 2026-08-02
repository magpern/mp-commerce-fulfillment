<?php
/**
 * `wp mpcf intake backfill` — idempotent intake for already-placed orders.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Cli;

use MPCF\Application\IntakeService;
use MPCF\Domain\OrderSource;
use WP_CLI;

/**
 * A thin WP-CLI adapter: {@see run_backfill()} holds every bit of real
 * logic (order discovery via {@see OrderSource}, ingestion via the exact
 * same {@see IntakeService} the live checkout hooks use) and returns a
 * plain result array with no WP-CLI dependency of its own — intentionally,
 * since `WP_CLI::error()` exits the PHP process, which would make that
 * logic untestable from PHPUnit if it lived only inside the command
 * callback. {@see backfill()} is the actual registered command: it calls
 * `run_backfill()` and translates the result into CLI-formatted output and
 * an exit code, nothing more.
 */
final class BackfillCommand {

	/**
	 * Discovers orders to ingest.
	 *
	 * @var OrderSource
	 */
	private OrderSource $orders;

	/**
	 * Idempotent order-to-fulfillment intake — the same service the live
	 * order platform's own checkout hooks use; this class never
	 * reimplements any part of it.
	 *
	 * @var IntakeService
	 */
	private IntakeService $intake;

	/**
	 * Builds the command.
	 *
	 * @param OrderSource   $orders Discovers orders to ingest.
	 * @param IntakeService $intake Idempotent order-to-fulfillment intake.
	 */
	public function __construct( OrderSource $orders, IntakeService $intake ) {
		$this->orders = $orders;
		$this->intake = $intake;
	}

	/**
	 * Registers the command.
	 */
	public function register(): void {
		WP_CLI::add_command( 'mpcf intake backfill', array( $this, 'backfill' ) );
	}

	/**
	 * Ingests every order in a given status that has not already been
	 * ingested.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Order status to scan, without the "wc-" prefix.
	 * ---
	 * default: processing
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mpcf intake backfill --status=processing
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function backfill( array $args, array $assoc_args ): void {
		unset( $args );

		$status = isset( $assoc_args['status'] ) ? sanitize_key( $assoc_args['status'] ) : 'processing';

		WP_CLI::log( "Scanning orders with status \"{$status}\"..." );

		$result = $this->run_backfill( $status );

		WP_CLI::log( "Inspected: {$result['inspected']}" );
		WP_CLI::log( "Created: {$result['created']}" );
		WP_CLI::log( "Already ingested: {$result['already_ingested']}" );
		WP_CLI::log( "Failed: {$result['failed']}" );

		if ( $result['failed'] > 0 ) {
			WP_CLI::error(
				sprintf(
					'%1$d order(s) could not be ingested (order ids: %2$s).',
					$result['failed'],
					implode( ', ', $result['failed_order_ids'] )
				)
			);

			return;
		}

		WP_CLI::success( 'Backfill complete.' );
	}

	/**
	 * Runs the backfill and returns its counts. Never prints anything and
	 * never exits — deliberately, so this is directly unit- and
	 * integration-testable without a WP-CLI runtime. Order ids only in the
	 * result, never a customer name or address (invariant: no PII in CLI
	 * output extends to this method's own return value, since {@see backfill()}
	 * prints it verbatim).
	 *
	 * @param string $status Order status to scan.
	 * @return array{inspected:int,created:int,already_ingested:int,failed:int,failed_order_ids:list<int>}
	 */
	public function run_backfill( string $status ): array {
		$result = array(
			'inspected'        => 0,
			'created'          => 0,
			'already_ingested' => 0,
			'failed'           => 0,
			'failed_order_ids' => array(),
		);

		foreach ( $this->orders->find_ids_by_status( $status ) as $order_id ) {
			++$result['inspected'];

			$outcome = $this->intake->intake( $order_id );

			if ( ! $outcome->is_success() ) {
				++$result['failed'];
				$result['failed_order_ids'][] = $order_id;
				continue;
			}

			if ( $outcome->was_created() ) {
				++$result['created'];
			} else {
				++$result['already_ingested'];
			}
		}

		return $result;
	}
}
