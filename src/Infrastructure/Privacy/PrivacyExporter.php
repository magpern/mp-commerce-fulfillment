<?php
/**
 * WordPress privacy personal-data exporter for MPCF.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Infrastructure\Privacy;

use MPCF\Infrastructure\Database\WpdbPrivacyRepository;

/**
 * Exports customer-linked fulfillment metadata (no binary files).
 */
final class PrivacyExporter {

	public const EXPORTER_EMAIL = 'mpcf-fulfillment-data';

	/**
	 * Builds the privacy exporter.
	 *
	 * @param WpdbPrivacyRepository $privacy Privacy DB access.
	 */
	public function __construct(
		private WpdbPrivacyRepository $privacy = new WpdbPrivacyRepository()
	) {
	}

	/**
	 * WP privacy exporter callback.
	 *
	 * @param string $email_address Request email.
	 * @param int    $page          1-indexed page.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		unset( $page );
		$order_ids = $this->order_ids_for_email( $email_address );
		if ( array() === $order_ids ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$items = array();
		foreach ( $this->privacy->fulfillments_for_orders( $order_ids ) as $row ) {
			$fid   = (int) $row['id'];
			$group = array(
				'group_id'          => 'mpcf-fulfillments',
				'group_label'       => __( 'MP Commerce Fulfillment', 'mp-commerce-fulfillment' ),
				'group_description' => __( 'Warehouse fulfillment records linked to your orders.', 'mp-commerce-fulfillment' ),
				'item_id'           => 'mpcf-fulfillment-' . $fid,
				'data'              => array(
					array(
						'name'  => __( 'Fulfillment ID', 'mp-commerce-fulfillment' ),
						'value' => (string) $fid,
					),
					array(
						'name'  => __( 'Order ID', 'mp-commerce-fulfillment' ),
						'value' => (string) (int) $row['order_id'],
					),
					array(
						'name'  => __( 'State', 'mp-commerce-fulfillment' ),
						'value' => (string) $row['state'],
					),
					array(
						'name'  => __( 'Customer name snapshot', 'mp-commerce-fulfillment' ),
						'value' => (string) $row['customer_name_snapshot'],
					),
					array(
						'name'  => __( 'Created at', 'mp-commerce-fulfillment' ),
						'value' => (string) $row['created_at'],
					),
				),
			);

			foreach ( $this->privacy->notes_for_fulfillment( $fid ) as $note ) {
				$group['data'][] = array(
					'name'  => sprintf(
						/* translators: %d: note id */
						__( 'Note #%d', 'mp-commerce-fulfillment' ),
						(int) $note['id']
					),
					'value' => (string) $note['body'],
				);
			}

			foreach ( $this->privacy->media_meta_for_fulfillment( $fid ) as $media ) {
				$group['data'][] = array(
					'name'  => sprintf(
						/* translators: %d: media id */
						__( 'Photo metadata #%d', 'mp-commerce-fulfillment' ),
						(int) $media['id']
					),
					'value' => sprintf(
						'kind=%s sha256=%s bytes=%d deleted=%s',
						(string) $media['kind'],
						(string) $media['sha256'],
						(int) $media['bytes'],
						null === $media['deleted_at'] ? 'no' : 'yes'
					),
				);
			}

			$items[] = $group;
		}

		return array(
			'data' => $items,
			'done' => true,
		);
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
