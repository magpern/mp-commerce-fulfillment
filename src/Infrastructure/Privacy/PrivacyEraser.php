<?php
/**
 * WordPress privacy eraser — anonymize without breaking hash chains.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Privacy;

use MPCF\Infrastructure\Database\WpdbEventPrivacyAnonymizer;
use MPCF\Infrastructure\Database\WpdbPrivacyRepository;
use MPCF\Infrastructure\Files\ProtectedPhotoStore;

/**
 * Anonymizes erasable PII; retains audit payloads/hashes/order ids.
 */
final class PrivacyEraser {

	public const ERASER_EMAIL = 'mpcf-fulfillment-data';

	public const ANON_NAME = '[anonymized]';

	public const ANON_ACTOR = '[erased]';

	public const ANON_NOTE = '[note erased]';

	/**
	 * Builds the privacy eraser.
	 *
	 * @param WpdbPrivacyRepository      $privacy    Privacy DB access.
	 * @param WpdbEventPrivacyAnonymizer $anonymizer Event actor scrubber.
	 */
	public function __construct(
		private WpdbPrivacyRepository $privacy = new WpdbPrivacyRepository(),
		private WpdbEventPrivacyAnonymizer $anonymizer = new WpdbEventPrivacyAnonymizer()
	) {
	}

	/**
	 * WP privacy eraser callback.
	 *
	 * @param string $email_address Request email.
	 * @param int    $page          1-indexed page.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		unset( $page );
		$messages = array();
		$removed  = false;
		$retained = false;

		$user_id   = $this->user_id_for_email( $email_address );
		$order_ids = $this->order_ids_for_email( $email_address );
		$f_ids     = $this->privacy->fulfillment_ids_for_orders( $order_ids );

		if ( array() === $f_ids && null === $user_id ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array( __( 'No MPCF personal data found for this email.', 'mp-commerce-fulfillment' ) ),
				'done'           => true,
			);
		}

		foreach ( $f_ids as $fid ) {
			if ( $this->privacy->anonymize_customer_name( $fid, self::ANON_NAME ) ) {
				$removed = true;
			}
			if ( $this->privacy->anonymize_notes( $fid, self::ANON_NOTE ) ) {
				$removed = true;
			}
			if ( $this->soft_erase_photos( $fid ) ) {
				$removed = true;
			}
			if ( null !== $user_id ) {
				$scrubbed = $this->anonymizer->anonymize_actor_user_on_fulfillment( $fid, $user_id, self::ANON_ACTOR );
				if ( $scrubbed > 0 ) {
					$removed = true;
				}
			}
			$retained   = true;
			$messages[] = sprintf(
				/* translators: %d: fulfillment id */
				__( 'Fulfillment %d: snapshots anonymized; audit chain retained.', 'mp-commerce-fulfillment' ),
				$fid
			);
		}

		if ( null !== $user_id ) {
			$n = $this->anonymizer->anonymize_actor_user( $user_id, self::ANON_ACTOR );
			if ( $n > 0 ) {
				$removed    = true;
				$messages[] = sprintf(
					/* translators: %d: rows updated */
					__( 'Cleared actor_id on %d audit events for this user (labels anonymized).', 'mp-commerce-fulfillment' ),
					$n
				);
			}
		}

		$messages[] = __( 'Retained: event payloads, hashes, prev_hash, order_id links, operational ids (required for audit integrity).', 'mp-commerce-fulfillment' );

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained || true,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Sympathetic erase for a commerce order id.
	 *
	 * @param int $order_id Woo order id.
	 */
	public function erase_for_order_id( int $order_id ): void {
		$f_ids = $this->privacy->fulfillment_ids_for_orders( array( $order_id ) );
		foreach ( $f_ids as $fid ) {
			$this->privacy->anonymize_customer_name( $fid, self::ANON_NAME );
			$this->privacy->anonymize_notes( $fid, self::ANON_NOTE );
			$this->soft_erase_photos( $fid );
		}
	}

	/**
	 * Deletes photo bytes and marks media rows purged.
	 *
	 * @param int $fulfillment_id Fulfillment id.
	 */
	private function soft_erase_photos( int $fulfillment_id ): bool {
		$rows = $this->privacy->media_meta_for_fulfillment( $fulfillment_id );
		if ( array() === $rows ) {
			return false;
		}

		$store   = new ProtectedPhotoStore();
		$changed = false;
		$now     = gmdate( 'Y-m-d H:i:s' );

		foreach ( $rows as $row ) {
			if ( null !== $row['purged_at'] ) {
				continue;
			}
			foreach ( array( 'file_path', 'thumb_path' ) as $col ) {
				$rel = (string) ( $row[ $col ] ?? '' );
				if ( '' !== $rel ) {
					$abs = $store->absolute_path( $rel );
					if ( is_string( $abs ) && is_file( $abs ) ) {
						@unlink( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Privacy erase of protected bytes.
					}
				}
			}
			$this->privacy->mark_media_purged( $row, $now );
			$changed = true;
		}

		return $changed;
	}

	/**
	 * Resolves a WordPress user id from email, if any.
	 *
	 * @param string $email_address Customer email.
	 */
	private function user_id_for_email( string $email_address ): ?int {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return null;
		}

		return (int) $user->ID;
	}

	/**
	 * Resolves Woo order ids for a billing email.
	 *
	 * @param string $email_address Customer email.
	 * @return list<int>
	 */
	private function order_ids_for_email( string $email_address ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'billing_email' => $email_address,
				'limit'         => 100,
				'return'        => 'ids',
			)
		);

		return array_map( 'intval', is_array( $orders ) ? $orders : array() );
	}
}
