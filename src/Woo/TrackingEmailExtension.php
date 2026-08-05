<?php
/**
 * Extends store completed-order customer emails with tracking.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Woo;

use MPCF\Application\Notifications\NotificationConfigurationService;
use MPCF\Domain\CarrierRegistry;
use MPCF\Domain\Repository\FulfillmentRepository;
use MPCF\Domain\Repository\PackageRepository;
use MPCF\Domain\Repository\ShipmentRepository;
use MPCF\Domain\Shipping\Shipment;
use WC_Email;
use WC_Order;

/**
 * Responsibility: extend WooCommerce customer emails with tracking content
 * when the notification strategy includes COMPLETED_EMAIL / BOTH.
 */
final class TrackingEmailExtension {

	/**
	 * Configuration service.
	 *
	 * @var NotificationConfigurationService
	 */
	private NotificationConfigurationService $config;

	/**
	 * Fulfillment repository.
	 *
	 * @var FulfillmentRepository
	 */
	private FulfillmentRepository $fulfillments;

	/**
	 * Shipment repository.
	 *
	 * @var ShipmentRepository
	 */
	private ShipmentRepository $shipments;

	/**
	 * Package repository.
	 *
	 * @var PackageRepository
	 */
	private PackageRepository $packages;

	/**
	 * Carrier registry.
	 *
	 * @var CarrierRegistry
	 */
	private CarrierRegistry $carriers;

	/**
	 * Builds the extension.
	 *
	 * @param NotificationConfigurationService $config       Configuration.
	 * @param FulfillmentRepository            $fulfillments Fulfillments.
	 * @param ShipmentRepository               $shipments    Shipments.
	 * @param PackageRepository                $packages     Packages.
	 * @param CarrierRegistry                  $carriers     Carriers.
	 */
	public function __construct(
		NotificationConfigurationService $config,
		FulfillmentRepository $fulfillments,
		ShipmentRepository $shipments,
		PackageRepository $packages,
		CarrierRegistry $carriers
	) {
		$this->config       = $config;
		$this->fulfillments = $fulfillments;
		$this->shipments    = $shipments;
		$this->packages     = $packages;
		$this->carriers     = $carriers;
	}

	/**
	 * Registers WooCommerce email hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_tracking_block' ), 20, 4 );
	}

	/**
	 * Appends tracking HTML to the completed-order customer email.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Whether this email is for admins.
	 * @param bool     $plain_text    Whether plain text.
	 * @param WC_Email $email         Email object.
	 */
	public function render_tracking_block( $order, $sent_to_admin, $plain_text, $email ): void {
		if ( $sent_to_admin || ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! $email instanceof WC_Email || 'customer_completed_order' !== $email->id ) {
			return;
		}

		if ( ! $this->config->strategy()->includes_completed_email() ) {
			return;
		}

		$blocks = $this->tracking_blocks_for_order( (int) $order->get_id() );
		if ( array() === $blocks ) {
			return;
		}

		$config = $this->config->get();

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Tracking', 'mp-commerce-fulfillment' ) . "\n";
			if ( '' !== $config->email_introduction() ) {
				echo esc_html( $config->email_introduction() ) . "\n";
			}
			foreach ( $blocks as $block ) {
				echo esc_html( $block['carrier_label'] ) . ': ' . esc_html( $block['tracking_number'] ) . "\n";
				if ( null !== $block['tracking_url'] ) {
					echo esc_url( $block['tracking_url'] ) . "\n";
				}
			}
			if ( '' !== $config->tracking_message_footer() ) {
				echo esc_html( $config->tracking_message_footer() ) . "\n";
			}
			if ( '' !== $config->email_signature() ) {
				echo esc_html( $config->email_signature() ) . "\n";
			}
			return;
		}

		echo '<div class="mpcf-tracking-block" style="margin:24px 0;">';
		echo '<h2>' . esc_html__( 'Tracking', 'mp-commerce-fulfillment' ) . '</h2>';
		if ( '' !== $config->email_introduction() ) {
			echo '<p>' . nl2br( esc_html( $config->email_introduction() ) ) . '</p>';
		}
		foreach ( $blocks as $block ) {
			echo '<p><strong>' . esc_html( $block['carrier_label'] ) . '</strong><br />';
			if ( null !== $block['tracking_url'] && '' !== $block['tracking_url'] ) {
				echo '<a href="' . esc_url( $block['tracking_url'] ) . '">' . esc_html( $block['tracking_number'] ) . '</a>';
			} else {
				echo esc_html( $block['tracking_number'] );
			}
			echo '</p>';
		}
		if ( '' !== $config->tracking_message_footer() ) {
			echo '<p>' . nl2br( esc_html( $config->tracking_message_footer() ) ) . '</p>';
		}
		if ( '' !== $config->email_signature() ) {
			echo '<p>' . nl2br( esc_html( $config->email_signature() ) ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Collects tracking rows for all shipped consignments on an order.
	 *
	 * @param int $order_id Order id.
	 * @return list<array{carrier_label: string, tracking_number: string, tracking_url: string|null}>
	 */
	private function tracking_blocks_for_order( int $order_id ): array {
		$blocks       = array();
		$fulfillments = $this->fulfillments->find_all_by_order_id( $order_id );

		foreach ( $fulfillments as $fulfillment ) {
			foreach ( $this->shipments->find_for_fulfillment( (int) $fulfillment->id() ) as $shipment ) {
				if ( ! in_array( $shipment->status(), array( Shipment::STATUS_SHIPPED, Shipment::STATUS_DELIVERED ), true ) ) {
					continue;
				}

				$packages = $this->packages->find_for_shipment( (int) $shipment->id() );
				$label    = $this->carriers->label_for( $shipment->carrier_id() );

				$added_colli = false;
				foreach ( $packages as $package ) {
					$colli = (string) $package->tracking_number();
					if ( '' === $colli ) {
						continue;
					}
					$blocks[]    = array(
						'carrier_label'   => $label,
						'tracking_number' => $colli,
						'tracking_url'    => $this->carriers->tracking_url_for( $shipment->carrier_id(), $colli ),
					);
					$added_colli = true;
				}

				if ( $added_colli ) {
					continue;
				}

				$number = (string) $shipment->tracking()->number();
				if ( '' === $number ) {
					continue;
				}

				$url = $shipment->tracking()->url();
				if ( null === $url || '' === $url ) {
					$url = $this->carriers->tracking_url_for( $shipment->carrier_id(), $number );
				}

				$blocks[] = array(
					'carrier_label'   => $label,
					'tracking_number' => $number,
					'tracking_url'    => $url,
				);
			}
		}

		return $blocks;
	}
}
