<?php
/**
 * `wp mpcf audit verify` — hash-chain verification.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Cli;

use MPCF\Application\Diagnostics\AuditChainVerifier;
use MPCF\Infrastructure\Database\WpdbDiagnosticsReader;
use WP_CLI;

/**
 * Thin WP-CLI adapter over AuditChainVerifier.
 */
final class AuditCommand {

	/**
	 * Builds the command handler.
	 *
	 * @param AuditChainVerifier    $verifier Chain verifier.
	 * @param WpdbDiagnosticsReader $reader   Diagnostics SQL reader.
	 */
	public function __construct(
		private AuditChainVerifier $verifier,
		private WpdbDiagnosticsReader $reader = new WpdbDiagnosticsReader()
	) {
	}

	/**
	 * Registers the command.
	 */
	public function register(): void {
		WP_CLI::add_command( 'mpcf audit verify', array( $this, 'verify' ) );
	}

	/**
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Fulfillment id to verify.
	 *
	 * [--all]
	 * : Verify every fulfillment that has events (bounded sample if huge — all rows).
	 *
	 * [--limit=<n>]
	 * : With --all, maximum fulfillments to verify (default 500).
	 *
	 * @param array<int, string>    $args       Positional.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function verify( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['all'] ) ) {
			$limit = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 500;
			$this->verify_all( $limit );
			return;
		}

		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $id <= 0 ) {
			WP_CLI::error( 'Usage: wp mpcf audit verify <fulfillment_id> | --all [--limit=N]' );
		}

		$result = $this->verifier->verify_fulfillment( $id );
		if ( ! $result['ok'] ) {
			WP_CLI::error( sprintf( 'FAIL fulfillment %d: %s', $id, (string) $result['error'] ) );
		}

		WP_CLI::success( sprintf( 'OK fulfillment %d (%d events).', $id, $result['events'] ) );
	}

	/**
	 * Verifies up to $limit distinct fulfillments that have events.
	 *
	 * @param int $limit Maximum fulfillments to verify.
	 */
	private function verify_all( int $limit ): void {
		$ids = $this->reader->fulfillment_ids_with_events( $limit );

		$ok   = 0;
		$fail = 0;
		foreach ( $ids as $id ) {
			$result = $this->verifier->verify_fulfillment( (int) $id );
			if ( $result['ok'] ) {
				++$ok;
			} else {
				++$fail;
				WP_CLI::warning( sprintf( 'FAIL %d: %s', (int) $id, (string) $result['error'] ) );
			}
		}

		WP_CLI::log( sprintf( 'Verified %d fulfillments: ok=%d fail=%d', count( $ids ), $ok, $fail ) );
		if ( $fail > 0 ) {
			WP_CLI::error( 'One or more chains failed verification.', false );
			exit( 1 );
		}

		WP_CLI::success( 'All sampled chains OK.' );
	}
}
