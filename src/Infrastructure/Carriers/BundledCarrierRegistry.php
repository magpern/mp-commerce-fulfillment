<?php
/**
 * Bundled, data-driven carrier registry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Carriers;

use MPCF\Domain\CarrierRegistry;
use MPCF\Domain\Shipping\Carrier;
use MPCF\Domain\TrackingUrlResolver;

/**
 * Immutable EU-skewed carrier registry (Architecture Plan §11 / M5-A).
 * Applies `mpcf_carriers`, validates every entry, rejects malformed
 * definitions with a log line, and continues loading remaining valid
 * carriers — same resilience philosophy as DocumentTypeRegistry.
 *
 * Definitions are never mutated at runtime. Merchant preferences
 * (default carrier, notification strategy) belong in Settings.
 */
final class BundledCarrierRegistry implements CarrierRegistry {

	public const OTHER = CarrierRegistry::OTHER;

	/**
	 * Tracking URL resolver.
	 *
	 * @var TrackingUrlResolver
	 */
	private TrackingUrlResolver $resolver;

	/**
	 * Optional reject logger (message string). Defaults to WC logger /
	 * error_log.
	 *
	 * @var callable(string):void|null
	 */
	private $on_reject;

	/**
	 * Builds the registry.
	 *
	 * @param TrackingUrlResolver|null $resolver  URL resolver (default template expander).
	 * @param callable|null            $on_reject Optional reject logger for tests.
	 */
	public function __construct( ?TrackingUrlResolver $resolver = null, ?callable $on_reject = null ) {
		$this->resolver  = $resolver ?? new TemplateTrackingUrlResolver();
		$this->on_reject = $on_reject;
	}

	/**
	 * Bundled definitions keyed by id (before the public filter).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function bundled_definitions(): array {
		return array(
			'postnord'    => array(
				'id'                      => 'postnord',
				'label'                   => 'PostNord',
				'tracking_url_template'   => 'https://tracking.postnord.com/en/?id={tracking}',
				'tracking_number_pattern' => '^[A-Za-z0-9]{8,35}$',
				'phone_required'          => false,
			),
			'dhl'         => array(
				'id'                      => 'dhl',
				'label'                   => 'DHL',
				'tracking_url_template'   => 'https://www.dhl.com/global-en/home/tracking.html?tracking-id={tracking}',
				'tracking_number_pattern' => '^[A-Za-z0-9]{10,39}$',
				'phone_required'          => false,
			),
			'bring'       => array(
				'id'                      => 'bring',
				'label'                   => 'Bring',
				'tracking_url_template'   => 'https://tracking.bring.com/tracking/{tracking}',
				'tracking_number_pattern' => '^[A-Za-z0-9]{7,35}$',
				'phone_required'          => false,
			),
			'dpd'         => array(
				'id'                      => 'dpd',
				'label'                   => 'DPD',
				'tracking_url_template'   => 'https://tracking.dpd.de/status/en_US/parcel/{tracking}',
				'tracking_number_pattern' => '^[A-Za-z0-9]{10,28}$',
				'phone_required'          => false,
			),
			'gls'         => array(
				'id'                      => 'gls',
				'label'                   => 'GLS',
				'tracking_url_template'   => 'https://gls-group.eu/EU/en/parcel-tracking?match={tracking}',
				'tracking_number_pattern' => '^[A-Za-z0-9]{8,20}$',
				'phone_required'          => false,
			),
			'ups'         => array(
				'id'                      => 'ups',
				'label'                   => 'UPS',
				'tracking_url_template'   => 'https://www.ups.com/track?tracknum={tracking}',
				'tracking_number_pattern' => '^(1Z[A-Z0-9]{16}|[A-Za-z0-9]{9,18})$',
				'phone_required'          => false,
			),
			'db_schenker' => array(
				'id'                      => 'db_schenker',
				'label'                   => 'DB Schenker',
				'tracking_url_template'   => 'https://www.dbschenker.com/app/tracking-public/?refNumber={tracking}',
				'tracking_number_pattern' => '^[A-Za-z0-9-]{6,40}$',
				'phone_required'          => false,
			),
			'budbee'      => array(
				'id'                      => 'budbee',
				'label'                   => 'Budbee',
				'tracking_url_template'   => 'https://tracking.budbee.com/{tracking}',
				'tracking_number_pattern' => '^[A-Za-z0-9-]{6,40}$',
				'phone_required'          => true,
			),
			'instabox'    => array(
				'id'                      => 'instabox',
				'label'                   => 'Instabox',
				'tracking_url_template'   => 'https://tracking.instabox.io/{tracking}',
				'tracking_number_pattern' => '^[A-Za-z0-9-]{6,40}$',
				'phone_required'          => true,
			),
			self::OTHER   => array(
				'id'                      => self::OTHER,
				'label'                   => 'Other',
				'tracking_url_template'   => null,
				'tracking_number_pattern' => null,
				'phone_required'          => false,
			),
		);
	}

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
	public function all(): array {
		return array_values(
			array_map(
				static fn( Carrier $carrier ): array => $carrier->to_array(),
				$this->resolved()
			)
		);
	}

	/**
	 * One validated carrier by id, or null when unknown.
	 *
	 * @param string $carrier_id Carrier registry key.
	 */
	public function get( string $carrier_id ): ?Carrier {
		$carriers = $this->resolved();

		return $carriers[ $carrier_id ] ?? null;
	}

	/**
	 * A carrier's display label, or the id itself if unregistered.
	 *
	 * @param string $carrier_id Carrier registry key.
	 */
	public function label_for( string $carrier_id ): string {
		$carrier = $this->get( $carrier_id );

		return null !== $carrier ? $carrier->label() : $carrier_id;
	}

	/**
	 * The tracking URL a carrier's template derives for a tracking number.
	 *
	 * @param string $carrier_id      Carrier registry key.
	 * @param string $tracking_number Tracking number to build a URL for.
	 */
	public function tracking_url_for( string $carrier_id, string $tracking_number ): ?string {
		$carrier = $this->get( $carrier_id );

		if ( null === $carrier ) {
			return null;
		}

		return $this->resolver->resolve( $carrier, $tracking_number );
	}

	/**
	 * Resolved, validated carriers keyed by id (display order preserved).
	 *
	 * @return array<string, Carrier>
	 */
	private function resolved(): array {
		$raw = self::bundled_definitions();

		/**
		 * Filters the carrier registry.
		 *
		 * Integrators may add or amend definitions. Malformed entries are
		 * rejected, logged, and skipped; remaining valid carriers load
		 * normally. Definitions are immutable after registration — do not
		 * use this filter for runtime merchant preferences (use Settings).
		 *
		 * @since 0.5.0
		 *
		 * @param array<string, array<string, mixed>|Carrier> $carriers Bundled definitions keyed by id.
		 */
		$filtered = apply_filters( 'mpcf_carriers', $raw );

		if ( ! is_array( $filtered ) ) {
			$this->reject( 'mpcf_carriers filter returned a non-array; reverting to bundled definitions.' );
			$filtered = $raw;
		}

		$carriers = array();

		foreach ( $filtered as $key => $entry ) {
			$carrier = $this->normalize_entry( $key, $entry );

			if ( null === $carrier ) {
				$id_hint = is_array( $entry ) && isset( $entry['id'] ) ? (string) $entry['id'] : (string) $key;
				$this->reject( sprintf( 'Rejected malformed carrier registration "%s".', $id_hint ) );
				continue;
			}

			if ( isset( $carriers[ $carrier->id() ] ) ) {
				$this->reject(
					sprintf(
						'Duplicate carrier id "%s"; later definition replaces the earlier one.',
						$carrier->id()
					)
				);
			}

			$carriers[ $carrier->id() ] = $carrier;
		}

		// `other` is always available so merchants are never blocked.
		if ( ! isset( $carriers[ self::OTHER ] ) ) {
			$other = Carrier::define( $raw[ self::OTHER ] );
			if ( $other->is_valid() ) {
				$carriers[ self::OTHER ] = $other;
			}
		}

		return $carriers;
	}

	/**
	 * Normalizes one registry map entry into a Carrier.
	 *
	 * @param mixed $key   Array key from the filtered map.
	 * @param mixed $entry Definition array or Carrier.
	 */
	private function normalize_entry( $key, $entry ): ?Carrier {
		if ( $entry instanceof Carrier ) {
			return $entry->is_valid() ? $entry : null;
		}

		if ( ! is_array( $entry ) ) {
			return null;
		}

		if ( ! isset( $entry['id'] ) && is_string( $key ) && '' !== $key ) {
			$entry['id'] = $key;
		}

		$carrier = Carrier::define( $entry );

		return $carrier->is_valid() ? $carrier : null;
	}

	/**
	 * Logs a rejected or anomalous registry entry without aborting load.
	 *
	 * @param string $message Human-readable rejection reason.
	 */
	private function reject( string $message ): void {
		if ( null !== $this->on_reject ) {
			( $this->on_reject )( $message );
			return;
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'mpcf-carriers' ) );
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fallback when WC logger is unavailable (unit tests / early boot).
		error_log( '[mpcf-carriers] ' . $message );
	}
}
