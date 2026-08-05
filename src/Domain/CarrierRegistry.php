<?php
/**
 * Port to the carrier data registry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

use MPCF\Domain\Shipping\Carrier;

/**
 * Architecture Plan §11 / M5-A: carriers are data, not code. The bundled
 * EU-skewed set lives in {@see \MPCF\Infrastructure\Carriers\BundledCarrierRegistry}
 * and is filterable via `mpcf_carriers`. Definitions are immutable after
 * registration; runtime merchant preferences belong in Settings (M5-B).
 * Includes an "other" entry so no merchant is blocked on an unbundled carrier.
 */
interface CarrierRegistry {

	/**
	 * Universal fallback carrier id — always present in the registry so
	 * merchants are never blocked on an unbundled carrier.
	 */
	public const OTHER = 'other';

	/**
	 * Every registered carrier, in display order.
	 *
	 * @return list<array{
	 *     id: string,
	 *     label: string,
	 *     tracking_url_template: string|null,
	 *     tracking_number_pattern: string|null,
	 *     phone_required: bool
	 * }>
	 */
	public function all(): array;

	/**
	 * One validated carrier by id, or null when unknown.
	 *
	 * @param string $carrier_id Carrier registry key.
	 */
	public function get( string $carrier_id ): ?Carrier;

	/**
	 * A carrier's display label, or the id itself if unregistered.
	 *
	 * @param string $carrier_id Carrier registry key.
	 */
	public function label_for( string $carrier_id ): string;

	/**
	 * The tracking URL a carrier's template derives for a tracking number,
	 * or null if the carrier is unregistered or has no template. Delegates
	 * to {@see TrackingUrlResolver}.
	 *
	 * @param string $carrier_id      Carrier registry key.
	 * @param string $tracking_number Tracking number to build a URL for.
	 */
	public function tracking_url_for( string $carrier_id, string $tracking_number ): ?string;
}
