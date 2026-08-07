<?php
/**
 * WooCommerce privacy sympathy — order anonymization → MPCF erase.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Woo;

use MPCF\Infrastructure\Privacy\PrivacyEraser;

/**
 * Confines `woocommerce_*` privacy hooks to src/Woo/ (I8).
 */
final class PrivacyHooks {

	/**
	 * Builds the Woo privacy hooks adapter.
	 *
	 * @param PrivacyEraser $eraser Privacy eraser.
	 */
	public function __construct(
		private PrivacyEraser $eraser
	) {
	}

	/**
	 * Registers WC privacy hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( $this, 'on_order_anonymized' ), 20, 1 );
	}

	/**
	 * Erases MPCF data when Woo anonymizes an order.
	 *
	 * @param mixed $order WC order object.
	 */
	public function on_order_anonymized( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return;
		}

		$this->eraser->erase_for_order_id( (int) $order->get_id() );
	}
}
