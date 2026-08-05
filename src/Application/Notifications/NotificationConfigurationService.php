<?php
/**
 * Loads and validates merchant notification configuration.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Notifications;

use MPCF\Domain\CarrierRegistry;
use MPCF\Domain\Notification\NotificationStrategy;
use MPCF\Settings;

/**
 * Configuration-only service for M5-B. Reads Settings + CarrierRegistry,
 * never depends on Admin UI, and never sends notifications. Always returns
 * an immutable {@see NotificationConfiguration} in a valid state.
 */
final class NotificationConfigurationService {

	/**
	 * Fallback subject when the stored subject is empty after sanitize.
	 */
	public const DEFAULT_SUBJECT = 'Your order has shipped';

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Carrier registry.
	 *
	 * @var CarrierRegistry
	 */
	private CarrierRegistry $carriers;

	/**
	 * Builds the configuration service.
	 *
	 * @param Settings        $settings Plugin settings.
	 * @param CarrierRegistry $carriers Carrier registry.
	 */
	public function __construct( Settings $settings, CarrierRegistry $carriers ) {
		$this->settings = $settings;
		$this->carriers = $carriers;
	}

	/**
	 * Current validated configuration. Never exposes invalid state.
	 */
	public function get(): NotificationConfiguration {
		$data = $this->settings->get();

		$strategy = NotificationStrategy::from( $data['notification_strategy'] ?? null );
		$carrier  = $this->resolve_default_carrier( (string) ( $data['default_carrier_id'] ?? '' ) );
		$subject  = (string) ( $data['notification_email_subject'] ?? '' );

		if ( '' === $subject ) {
			$subject = self::DEFAULT_SUBJECT;
		}

		return new NotificationConfiguration(
			$strategy,
			$carrier,
			(string) ( $data['notification_sender_name'] ?? '' ),
			(string) ( $data['notification_reply_to'] ?? '' ),
			(string) ( $data['notification_tracking_footer'] ?? '' ),
			$subject,
			(string) ( $data['notification_email_introduction'] ?? '' ),
			(string) ( $data['notification_email_signature'] ?? '' )
		);
	}

	/**
	 * Delivery strategy.
	 */
	public function strategy(): NotificationStrategy {
		return $this->get()->strategy();
	}

	/**
	 * Effective default carrier id (always registry-valid).
	 */
	public function default_carrier_id(): string {
		return $this->get()->default_carrier_id();
	}

	/**
	 * Resolves a stored carrier id against the registry. Empty or unknown
	 * values fall back to {@see CarrierRegistry::OTHER}.
	 *
	 * @param string $carrier_id Stored carrier id.
	 */
	private function resolve_default_carrier( string $carrier_id ): string {
		$carrier_id = trim( $carrier_id );

		if ( '' === $carrier_id ) {
			return CarrierRegistry::OTHER;
		}

		if ( null === $this->carriers->get( $carrier_id ) ) {
			return CarrierRegistry::OTHER;
		}

		return $carrier_id;
	}
}
