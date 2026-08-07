<?php
/**
 * Read-only operational diagnostics façade.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Diagnostics;

/**
 * Runs CheckerRegistry. Never mutates.
 */
final class DoctorService {

	/**
	 * Builds the doctor service.
	 *
	 * @param CheckerRegistry $registry Shared checker registry.
	 */
	public function __construct(
		private CheckerRegistry $registry
	) {
	}

	/**
	 * Full doctor report.
	 *
	 * @param string|null $checker_id Optional single checker id.
	 */
	public function run( ?string $checker_id = null ): DoctorReport {
		$results = null === $checker_id || '' === $checker_id
			? $this->registry->run_all()
			: $this->registry->run_one( $checker_id );

		return new DoctorReport( $results );
	}

	/**
	 * Exposes the registry for Site Health adapters (same checkers).
	 */
	public function registry(): CheckerRegistry {
		return $this->registry;
	}
}
