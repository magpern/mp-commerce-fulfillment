<?php
/**
 * Assembles a combined wave picking list document model.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Engine\DocumentAssembler;

use MPCF\Domain\Barcode\BarcodePayload;
use MPCF\Domain\Document\DocumentModel;
use MPCF\Domain\Wave\Wave;

/**
 * Architecture Plan Part X.3 — `wave_picking_list`. Sorts by location hint;
 * does not read inventory plugins.
 */
final class WavePickingListAssembler {

	/**
	 * Document-type registry key.
	 */
	public const DOC_TYPE = 'wave_picking_list';

	/**
	 * Bundled template version.
	 */
	public const TEMPLATE_VERSION = '1';

	/**
	 * Assembles a wave picking list.
	 *
	 * @param Wave                 $wave       Wave.
	 * @param array<string, mixed> $walk       Walk model from WaveWalkBuilder.
	 * @param string               $store_name Store display name.
	 * @param array<string, mixed> $branding   Branding snapshot.
	 * @param string               $template_version Template version.
	 */
	public static function assemble(
		Wave $wave,
		array $walk,
		string $store_name,
		array $branding = array(),
		string $template_version = self::TEMPLATE_VERSION
	): DocumentModel {
		if ( array() === $branding ) {
			$branding = array( 'store_name' => $store_name );
		}

		$wave_id = (int) $wave->id();
		$rows    = isset( $walk['rows'] ) && is_array( $walk['rows'] ) ? $walk['rows'] : array();
		$items   = array();

		foreach ( $rows as $row ) {
			$items[] = array(
				'sku'               => (string) ( $row['sku_snapshot'] ?? '' ),
				'name'              => (string) ( $row['name_snapshot'] ?? '' ),
				'location_snapshot' => $row['location_snapshot'] ?? null,
				'required_qty'      => (int) ( $row['required_qty'] ?? 0 ),
				'done_qty'          => (int) ( $row['done_qty'] ?? 0 ),
				'complete'          => ! empty( $row['complete'] ),
				'product_id'        => (int) ( $row['product_id'] ?? 0 ),
				'variation_id'      => (int) ( $row['variation_id'] ?? 0 ),
				'allocations'       => isset( $row['allocations'] ) && is_array( $row['allocations'] ) ? $row['allocations'] : array(),
			);
		}

		$member_labels = array();
		foreach ( $wave->members() as $member ) {
			$member_labels[] = '#' . $member->fulfillment_id();
		}

		return new DocumentModel(
			self::DOC_TYPE,
			$wave_id > 0 ? $wave_id : 0,
			$wave->title() !== '' ? $wave->title() : ( 'Wave #' . $wave_id ),
			sprintf( '%d members', $wave->member_count() ),
			$member_labels,
			$store_name,
			$items,
			array(),
			$wave_id > 0 ? BarcodePayload::wave( $wave_id )->encode() : '',
			$wave->state(),
			$template_version,
			$branding,
			null,
			0,
			''
		);
	}
}
