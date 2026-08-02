<?php
/**
 * Bundled, data-driven carrier registry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Carriers;

use MPCF\Domain\CarrierRegistry;

/**
 * Architecture Plan §IV.6: deliberately minimal for Milestone 2 — a small,
 * recognizable set plus "other", which accepts a free-text label and a
 * manual tracking URL (Shipment::set_carrier()/set_tracking() already
 * support that: any string is a valid carrier id, this registry only
 * supplies known ones' display labels and URL templates). The real
 * EU-skewed bundled set (tracking-number format hints, phone-required
 * flags, the `mpcf_carriers` filter) is Milestone 4's job — no merchant is
 * blocked on a carrier this class does not recognize.
 */
final class BundledCarrierRegistry implements CarrierRegistry {

	public const OTHER = 'other';

	/**
	 * Bundled carriers: id, label, and a tracking-URL template with a
	 * `{tracking}` placeholder (null for "other", which has no template).
	 *
	 * @var list<array{id: string, label: string, tracking_url_template: string|null}>
	 */
	private const CARRIERS = array(
		array(
			'id'                    => 'postnord',
			'label'                 => 'PostNord',
			'tracking_url_template' => 'https://tracking.postnord.com/en/?id={tracking}',
		),
		array(
			'id'                    => 'dhl',
			'label'                 => 'DHL',
			'tracking_url_template' => 'https://www.dhl.com/global-en/home/tracking.html?tracking-id={tracking}',
		),
		array(
			'id'                    => 'ups',
			'label'                 => 'UPS',
			'tracking_url_template' => 'https://www.ups.com/track?tracknum={tracking}',
		),
		array(
			'id'                    => self::OTHER,
			'label'                 => 'Other',
			'tracking_url_template' => null,
		),
	);

	/**
	 * Every registered carrier, in display order.
	 *
	 * @return list<array{id: string, label: string}>
	 */
	public function all(): array {
		return array_map(
			static fn( array $carrier ): array => array(
				'id'    => $carrier['id'],
				'label' => $carrier['label'],
			),
			self::CARRIERS
		);
	}

	/**
	 * A carrier's display label, or the id itself if unregistered.
	 *
	 * @param string $carrier_id Carrier registry key.
	 */
	public function label_for( string $carrier_id ): string {
		$carrier = $this->find( $carrier_id );

		return null !== $carrier ? $carrier['label'] : $carrier_id;
	}

	/**
	 * The tracking URL a carrier's template derives for a tracking number.
	 *
	 * @param string $carrier_id      Carrier registry key.
	 * @param string $tracking_number Tracking number to build a URL for.
	 */
	public function tracking_url_for( string $carrier_id, string $tracking_number ): ?string {
		$carrier = $this->find( $carrier_id );

		if ( null === $carrier || null === $carrier['tracking_url_template'] || '' === $tracking_number ) {
			return null;
		}

		return str_replace( '{tracking}', rawurlencode( $tracking_number ), $carrier['tracking_url_template'] );
	}

	/**
	 * Finds a bundled carrier by id.
	 *
	 * @param string $carrier_id Carrier registry key.
	 * @return array{id: string, label: string, tracking_url_template: string|null}|null
	 */
	private function find( string $carrier_id ): ?array {
		foreach ( self::CARRIERS as $carrier ) {
			if ( $carrier['id'] === $carrier_id ) {
				return $carrier;
			}
		}

		return null;
	}
}
