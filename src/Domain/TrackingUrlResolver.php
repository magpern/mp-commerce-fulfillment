<?php
/**
 * Port for resolving customer-facing tracking URLs.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Domain;

use MPCF\Domain\Shipping\Carrier;

/**
 * Builds a tracking URL from a carrier definition and tracking number.
 * Transport-independent — no email, notification, or live carrier API
 * concerns. The default infrastructure implementation expands
 * `tracking_url_template`; future carrier integrations may support locale,
 * country, checksum, or carrier-specific generation without redesigning
 * callers.
 */
interface TrackingUrlResolver {

	/**
	 * Resolves a customer tracking URL, or null when the carrier has no
	 * template or the tracking number is empty.
	 *
	 * @param Carrier $carrier         Registered carrier definition.
	 * @param string  $tracking_number Tracking number to place in the URL.
	 */
	public function resolve( Carrier $carrier, string $tracking_number ): ?string;
}
