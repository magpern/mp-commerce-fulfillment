<?php
/**
 * Default tracking-URL resolver: expands carrier templates.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Carriers;

use MPCF\Domain\Shipping\Carrier;
use MPCF\Domain\TrackingUrlResolver;

/**
 * Expands `{tracking}` in a carrier's `tracking_url_template` with a
 * URL-encoded tracking number. Does not call external APIs.
 */
final class TemplateTrackingUrlResolver implements TrackingUrlResolver {

	/**
	 * Resolves a customer tracking URL from a carrier template.
	 *
	 * @param Carrier $carrier         Registered carrier definition.
	 * @param string  $tracking_number Tracking number to place in the URL.
	 */
	public function resolve( Carrier $carrier, string $tracking_number ): ?string {
		$template = $carrier->tracking_url_template();

		if ( null === $template || '' === $tracking_number ) {
			return null;
		}

		return str_replace( '{tracking}', rawurlencode( $tracking_number ), $template );
	}
}
