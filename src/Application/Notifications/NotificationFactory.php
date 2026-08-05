<?php
/**
 * Builds Notification objects from shipment data + configuration.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application\Notifications;

use MPCF\Domain\CarrierRegistry;
use MPCF\Domain\CustomerEmailLookup;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\Notification\Notification;
use MPCF\Domain\Shipping\Package;
use MPCF\Domain\Shipping\Shipment;

/**
 * Converts shipment aggregates into transport-independent Notifications.
 * No sending. No WordPress.
 */
final class NotificationFactory {

	/**
	 * Configuration service.
	 *
	 * @var NotificationConfigurationService
	 */
	private NotificationConfigurationService $config;

	/**
	 * Carrier registry.
	 *
	 * @var CarrierRegistry
	 */
	private CarrierRegistry $carriers;

	/**
	 * Customer email lookup.
	 *
	 * @var CustomerEmailLookup
	 */
	private CustomerEmailLookup $emails;

	/**
	 * Builds the factory.
	 *
	 * @param NotificationConfigurationService $config   Configuration.
	 * @param CarrierRegistry                  $carriers Carrier registry.
	 * @param CustomerEmailLookup              $emails   Customer email lookup.
	 */
	public function __construct(
		NotificationConfigurationService $config,
		CarrierRegistry $carriers,
		CustomerEmailLookup $emails
	) {
		$this->config   = $config;
		$this->carriers = $carriers;
		$this->emails   = $emails;
	}

	/**
	 * Builds a notification for one shipped consignment, or null when the
	 * customer has no email / shipment is incomplete.
	 *
	 * @param Fulfillment $fulfillment Owning fulfillment.
	 * @param Shipment    $shipment    Shipped shipment.
	 * @param Package[]   $packages    Packages on the shipment.
	 */
	public function from_shipment( Fulfillment $fulfillment, Shipment $shipment, array $packages ): ?Notification {
		$order_id = $fulfillment->order_id();
		$email    = $this->emails->email_for_order( $order_id );

		if ( null === $email || '' === $email ) {
			return null;
		}

		$config  = $this->config->get();
		$carrier = $this->carriers->get( $shipment->carrier_id() );
		$label   = $this->carriers->label_for( $shipment->carrier_id() );

		$primary_number = (string) $shipment->tracking()->number();
		$primary_url    = $shipment->tracking()->url();

		if ( null === $primary_url || '' === $primary_url ) {
			$primary_url = $this->carriers->tracking_url_for( $shipment->carrier_id(), $primary_number );
		}

		$package_tracking = array();
		foreach ( $packages as $package ) {
			$colli = (string) $package->tracking_number();
			if ( '' === $colli ) {
				continue;
			}
			$url = null;
			if ( null !== $carrier ) {
				$url = $this->carriers->tracking_url_for( $shipment->carrier_id(), $colli );
			}
			$package_tracking[] = array(
				'tracking_number' => $colli,
				'tracking_url'    => $url,
			);
		}

		// Prefer colli numbers for display when present.
		$display_number = $primary_number;
		$display_url    = $primary_url;
		if ( array() !== $package_tracking ) {
			$display_number = $package_tracking[0]['tracking_number'];
			$display_url    = $package_tracking[0]['tracking_url'] ?? $primary_url;
		}

		$subject = $config->default_email_subject();
		$html    = $this->build_html( $config, $label, $display_number, $display_url, $package_tracking );
		$text    = $this->build_text( $config, $label, $display_number, $display_url, $package_tracking );

		return new Notification(
			$email,
			$subject,
			$html,
			$text,
			(int) $fulfillment->id(),
			(int) $shipment->id(),
			$order_id,
			$shipment->carrier_id(),
			$label,
			$display_number,
			$display_url,
			$package_tracking,
			$config->strategy()->value(),
			array(
				'sender_name' => $config->sender_name(),
				'reply_to'    => $config->reply_to_email(),
			)
		);
	}

	/**
	 * HTML body from merchant configuration + tracking.
	 *
	 * @param NotificationConfiguration                                             $config            Config snapshot.
	 * @param string                                                                $carrier_label     Carrier label.
	 * @param string                                                                $tracking_number   Primary number.
	 * @param string|null                                                           $tracking_url      Primary URL.
	 * @param array<int, array{tracking_number: string, tracking_url: string|null}> $package_tracking Colli rows.
	 */
	private function build_html(
		NotificationConfiguration $config,
		string $carrier_label,
		string $tracking_number,
		?string $tracking_url,
		array $package_tracking
	): string {
		$parts = array();

		if ( '' !== $config->email_introduction() ) {
			$parts[] = '<p>' . $this->escape_nl( $config->email_introduction() ) . '</p>';
		}

		$parts[] = '<p><strong>' . $this->e( $carrier_label ) . '</strong></p>';

		if ( '' !== $tracking_number ) {
			if ( null !== $tracking_url && '' !== $tracking_url ) {
				$parts[] = '<p><a href="' . $this->e( $tracking_url ) . '">' . $this->e( $tracking_number ) . '</a></p>';
			} else {
				$parts[] = '<p>' . $this->e( $tracking_number ) . '</p>';
			}
		}

		foreach ( $package_tracking as $row ) {
			if ( $row['tracking_number'] === $tracking_number ) {
				continue;
			}
			if ( null !== $row['tracking_url'] && '' !== $row['tracking_url'] ) {
				$parts[] = '<p><a href="' . $this->e( $row['tracking_url'] ) . '">' . $this->e( $row['tracking_number'] ) . '</a></p>';
			} else {
				$parts[] = '<p>' . $this->e( $row['tracking_number'] ) . '</p>';
			}
		}

		if ( '' !== $config->tracking_message_footer() ) {
			$parts[] = '<p>' . $this->escape_nl( $config->tracking_message_footer() ) . '</p>';
		}

		if ( '' !== $config->email_signature() ) {
			$parts[] = '<p>' . $this->escape_nl( $config->email_signature() ) . '</p>';
		}

		return implode( "\n", $parts );
	}

	/**
	 * Plain-text body.
	 *
	 * @param NotificationConfiguration                                             $config            Config snapshot.
	 * @param string                                                                $carrier_label     Carrier label.
	 * @param string                                                                $tracking_number   Primary number.
	 * @param string|null                                                           $tracking_url      Primary URL.
	 * @param array<int, array{tracking_number: string, tracking_url: string|null}> $package_tracking Colli rows.
	 */
	private function build_text(
		NotificationConfiguration $config,
		string $carrier_label,
		string $tracking_number,
		?string $tracking_url,
		array $package_tracking
	): string {
		$lines = array();

		if ( '' !== $config->email_introduction() ) {
			$lines[] = $config->email_introduction();
			$lines[] = '';
		}

		$lines[] = $carrier_label;
		if ( '' !== $tracking_number ) {
			$lines[] = $tracking_number;
			if ( null !== $tracking_url && '' !== $tracking_url ) {
				$lines[] = $tracking_url;
			}
		}

		foreach ( $package_tracking as $row ) {
			if ( $row['tracking_number'] === $tracking_number ) {
				continue;
			}
			$lines[] = $row['tracking_number'];
			if ( null !== $row['tracking_url'] && '' !== $row['tracking_url'] ) {
				$lines[] = $row['tracking_url'];
			}
		}

		if ( '' !== $config->tracking_message_footer() ) {
			$lines[] = '';
			$lines[] = $config->tracking_message_footer();
		}

		if ( '' !== $config->email_signature() ) {
			$lines[] = '';
			$lines[] = $config->email_signature();
		}

		return implode( "\n", $lines );
	}

	/**
	 * Escape for HTML attributes/text without WP helpers (Domain purity of Application).
	 *
	 * @param string $value Raw value.
	 */
	private function e( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Escape multiline text to HTML paragraphs-ish.
	 *
	 * @param string $value Raw multiline.
	 */
	private function escape_nl( string $value ): string {
		return nl2br( $this->e( $value ), false );
	}
}
