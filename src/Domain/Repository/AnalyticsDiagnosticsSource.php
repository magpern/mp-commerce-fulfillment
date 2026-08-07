<?php
/**
 * Port for bounded analytics diagnostic lists.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain\Repository;

use DateTimeImmutable;

/**
 * Read-only. No workflow mutations.
 */
interface AnalyticsDiagnosticsSource {

	/**
	 * Oldest open fulfillments for diagnostics.
	 *
	 * @param array             $open_states  Open workflow state keys.
	 * @param int               $warehouse_id Warehouse scope.
	 * @param DateTimeImmutable $now          Reference "now".
	 * @param int               $limit        Max rows.
	 * @return list<array<string, mixed>>
	 */
	public function slow_fulfillments( array $open_states, int $warehouse_id, DateTimeImmutable $now, int $limit = 25 ): array;

	/**
	 * Stalled active waves for diagnostics.
	 *
	 * @param int               $warehouse_id Warehouse scope.
	 * @param DateTimeImmutable $now          Reference "now".
	 * @param int               $limit        Max rows.
	 * @return list<array<string, mixed>>
	 */
	public function stalled_waves( int $warehouse_id, DateTimeImmutable $now, int $limit = 25 ): array;
}
