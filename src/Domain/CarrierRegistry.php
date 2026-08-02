<?php
/**
 * Port to the carrier data registry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

/**
 * Architecture Plan §IV.6: carriers are data, not code. The Milestone 2
 * bundled set (`Infrastructure\Carriers\BundledCarrierRegistry`) is
 * deliberately minimal and includes an "other" entry accepting a free-text
 * label and a manual tracking URL, so no merchant is blocked on a carrier
 * that isn't bundled — the real EU-skewed registry shape (format-validation
 * hints, phone-required flags, the `mpcf_carriers` filter) is M4's job.
 */
interface CarrierRegistry {

	/**
	 * Every registered carrier, in display order.
	 *
	 * @return list<array{id: string, label: string}>
	 */
	public function all(): array;

	/**
	 * A carrier's display label, or the id itself if unregistered.
	 *
	 * @param string $carrier_id Carrier registry key.
	 */
	public function label_for( string $carrier_id ): string;

	/**
	 * The tracking URL a carrier's template derives for a tracking number,
	 * or null if the carrier is unregistered or has no template.
	 *
	 * @param string $carrier_id      Carrier registry key.
	 * @param string $tracking_number Tracking number to build a URL for.
	 */
	public function tracking_url_for( string $carrier_id, string $tracking_number ): ?string;
}
