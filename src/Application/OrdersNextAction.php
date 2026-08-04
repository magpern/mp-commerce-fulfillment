<?php
/**
 * Operator-facing next-action and operational-state copy for Orders rows.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Application;

/**
 * Pure presentation mapping over Woo status + optional fulfillment state.
 * Never creates fulfillments and never evaluates workflow guards.
 */
final class OrdersNextAction {

	public const OPEN_WORKSPACE   = 'workspace';
	public const OPEN_WOOCOMMERCE = 'woocommerce';
	public const OPEN_NONE        = 'none';

	/**
	 * Describes operational state, next action, and open target for one row.
	 *
	 * @param string      $woo_status        WC status without `wc-`.
	 * @param string|null $fulfillment_state Fulfillment state key, or null.
	 * @return array{operational_state: string, next_action: string, open_target: string}
	 */
	public static function describe( string $woo_status, ?string $fulfillment_state ): array {
		$woo_status = strtolower( $woo_status );

		if ( in_array( $woo_status, array( 'cancelled', 'refunded' ), true ) ) {
			return self::pack(
				__( 'Cancelled', 'mp-commerce-fulfillment' ),
				__( 'No action', 'mp-commerce-fulfillment' ),
				null !== $fulfillment_state ? self::OPEN_WORKSPACE : self::OPEN_WOOCOMMERCE
			);
		}

		if ( null === $fulfillment_state ) {
			return self::without_fulfillment( $woo_status );
		}

		return self::with_fulfillment( $woo_status, $fulfillment_state );
	}

	/**
	 * Next-action copy when no fulfillment exists yet.
	 *
	 * @param string $woo_status WC status.
	 * @return array{operational_state: string, next_action: string, open_target: string}
	 */
	private static function without_fulfillment( string $woo_status ): array {
		switch ( $woo_status ) {
			case 'pending':
				return self::pack(
					__( 'Awaiting payment', 'mp-commerce-fulfillment' ),
					__( 'Awaiting payment', 'mp-commerce-fulfillment' ),
					self::OPEN_WOOCOMMERCE
				);
			case 'on-hold':
				return self::pack(
					__( 'On hold', 'mp-commerce-fulfillment' ),
					__( 'Awaiting payment confirmation', 'mp-commerce-fulfillment' ),
					self::OPEN_WOOCOMMERCE
				);
			case 'failed':
				return self::pack(
					__( 'Payment failed', 'mp-commerce-fulfillment' ),
					__( 'Awaiting payment', 'mp-commerce-fulfillment' ),
					self::OPEN_WOOCOMMERCE
				);
			case 'completed':
				return self::pack(
					__( 'Completed', 'mp-commerce-fulfillment' ),
					__( 'Completed', 'mp-commerce-fulfillment' ),
					self::OPEN_WOOCOMMERCE
				);
			default:
				return self::pack(
					self::woo_status_label( $woo_status ),
					__( 'Open in WooCommerce', 'mp-commerce-fulfillment' ),
					self::OPEN_WOOCOMMERCE
				);
		}
	}

	/**
	 * Next-action copy when a fulfillment is associated.
	 *
	 * @param string $woo_status        WC status.
	 * @param string $fulfillment_state Fulfillment state.
	 * @return array{operational_state: string, next_action: string, open_target: string}
	 */
	private static function with_fulfillment( string $woo_status, string $fulfillment_state ): array {
		unset( $woo_status );

		$map = array(
			'queued'      => array( __( 'Queued', 'mp-commerce-fulfillment' ), __( 'Start picking', 'mp-commerce-fulfillment' ) ),
			'picking'     => array( __( 'Picking', 'mp-commerce-fulfillment' ), __( 'Continue picking', 'mp-commerce-fulfillment' ) ),
			'picked'      => array( __( 'Picked', 'mp-commerce-fulfillment' ), __( 'Start packing', 'mp-commerce-fulfillment' ) ),
			'packing'     => array( __( 'Packing', 'mp-commerce-fulfillment' ), __( 'Continue packing', 'mp-commerce-fulfillment' ) ),
			'packed'      => array( __( 'Packed', 'mp-commerce-fulfillment' ), __( 'Ready to ship', 'mp-commerce-fulfillment' ) ),
			'shipped'     => array( __( 'Shipped', 'mp-commerce-fulfillment' ), __( 'Completed', 'mp-commerce-fulfillment' ) ),
			'delivered'   => array( __( 'Delivered', 'mp-commerce-fulfillment' ), __( 'Completed', 'mp-commerce-fulfillment' ) ),
			'completed'   => array( __( 'Completed', 'mp-commerce-fulfillment' ), __( 'Completed', 'mp-commerce-fulfillment' ) ),
			'problem'     => array( __( 'Problem', 'mp-commerce-fulfillment' ), __( 'Resolve problem', 'mp-commerce-fulfillment' ) ),
			'waiting'     => array( __( 'Waiting', 'mp-commerce-fulfillment' ), __( 'Resume when ready', 'mp-commerce-fulfillment' ) ),
			'backordered' => array( __( 'Backordered', 'mp-commerce-fulfillment' ), __( 'Resume when stocked', 'mp-commerce-fulfillment' ) ),
			'cancelled'   => array( __( 'Cancelled', 'mp-commerce-fulfillment' ), __( 'No action', 'mp-commerce-fulfillment' ) ),
		);

		if ( isset( $map[ $fulfillment_state ] ) ) {
			return self::pack( $map[ $fulfillment_state ][0], $map[ $fulfillment_state ][1], self::OPEN_WORKSPACE );
		}

		return self::pack( $fulfillment_state, __( 'Open workspace', 'mp-commerce-fulfillment' ), self::OPEN_WORKSPACE );
	}

	/**
	 * Human label for a known Woo status without naming WooCommerce helpers
	 * (invariant I8 — Application stays platform-agnostic).
	 *
	 * @param string $woo_status WC status key.
	 */
	private static function woo_status_label( string $woo_status ): string {
		$labels = array(
			'processing'     => __( 'Processing', 'mp-commerce-fulfillment' ),
			'checkout-draft' => __( 'Draft', 'mp-commerce-fulfillment' ),
		);

		return $labels[ $woo_status ] ?? $woo_status;
	}

	/**
	 * Packs the three presentation fields into one array.
	 *
	 * @param string $operational_state Operational state label.
	 * @param string $next_action       Next-action label.
	 * @param string $open_target       Open destination token.
	 * @return array{operational_state: string, next_action: string, open_target: string}
	 */
	private static function pack( string $operational_state, string $next_action, string $open_target ): array {
		return array(
			'operational_state' => $operational_state,
			'next_action'       => $next_action,
			'open_target'       => $open_target,
		);
	}
}
