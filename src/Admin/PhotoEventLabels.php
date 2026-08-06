<?php
/**
 * Operator-facing labels for package photography audit events.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Admin;

/**
 * Keeps raw photo event type strings out of operator UX.
 */
final class PhotoEventLabels {

	/**
	 * Human label for a photo event, or null when unrelated.
	 *
	 * @param string               $event_type Event type.
	 * @param array<string, mixed> $payload    Payload.
	 */
	public static function describe( string $event_type, array $payload = array() ): ?string {
		$kind_label = self::kind_label( isset( $payload['kind'] ) ? (string) $payload['kind'] : '' );
		$package_id = isset( $payload['package_id'] ) ? (int) $payload['package_id'] : 0;

		switch ( $event_type ) {
			case 'photo.captured':
				if ( $package_id > 0 ) {
					return sprintf(
						/* translators: 1: photo kind label (Sealed package|Contents), 2: package id */
						__( 'Package photo captured (%1$s) for package %2$d.', 'mp-commerce-fulfillment' ),
						$kind_label,
						$package_id
					);
				}

				return sprintf(
					/* translators: %s: photo kind label (Sealed package|Contents) */
					__( 'Package photo captured (%s).', 'mp-commerce-fulfillment' ),
					$kind_label
				);
			case 'photo.deleted':
				if ( $package_id > 0 ) {
					return sprintf(
						/* translators: 1: photo kind label (Sealed package|Contents), 2: package id */
						__( 'Package photo deleted (%1$s) for package %2$d.', 'mp-commerce-fulfillment' ),
						$kind_label,
						$package_id
					);
				}

				return sprintf(
					/* translators: %s: photo kind label (Sealed package|Contents) */
					__( 'Package photo deleted (%s).', 'mp-commerce-fulfillment' ),
					$kind_label
				);
			default:
				return null;
		}
	}

	/**
	 * Operator-facing kind label.
	 *
	 * @param string $kind Stored kind key.
	 */
	private static function kind_label( string $kind ): string {
		if ( 'contents' === $kind ) {
			return __( 'Contents', 'mp-commerce-fulfillment' );
		}

		if ( 'package' === $kind ) {
			return __( 'Sealed package', 'mp-commerce-fulfillment' );
		}

		return '' !== $kind ? $kind : __( 'Unknown', 'mp-commerce-fulfillment' );
	}
}
