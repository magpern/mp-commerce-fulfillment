<?php
/**
 * Bundled document-type definitions and filterable registry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Documents;

use MPCF\Capabilities;
use MPCF\Domain\Document\DocumentType;

/**
 * Deliberately small registry for the two M4 document types. Applies
 * `mpcf_document_types` and validates every entry before use — not a
 * plugin framework.
 */
final class DocumentTypeRegistry {

	/**
	 * Working states for picking_list (plus exception return_to of these).
	 *
	 * @var list<string>
	 */
	public const PICKING_LIST_STATES = array( 'queued', 'picking', 'picked' );

	/**
	 * Working states for packing_slip (plus exception return_to of these).
	 *
	 * @var list<string>
	 */
	public const PACKING_SLIP_STATES = array( 'packing', 'packed', 'shipped', 'delivered', 'completed' );

	/**
	 * Bundled definitions keyed by id (before the public filter).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function bundled_definitions(): array {
		return array(
			'packing_slip' => array(
				'id'               => 'packing_slip',
				'label'            => 'Packing slip',
				'assembler'        => 'packing_slip',
				'template_key'     => 'packing_slip',
				'renderer'         => DocumentType::RENDERER_HTML,
				'paper_size'       => 'A4',
				'capability'       => Capabilities::RENDER_DOCUMENTS,
				'allowed_states'   => self::PACKING_SLIP_STATES,
				'storage_policy'   => DocumentType::STORAGE_PRINT,
				'template_version' => '1',
			),
			'picking_list' => array(
				'id'               => 'picking_list',
				'label'            => 'Picking list',
				'assembler'        => 'picking_list',
				'template_key'     => 'picking_list',
				'renderer'         => DocumentType::RENDERER_HTML,
				'paper_size'       => 'A4',
				'capability'       => Capabilities::RENDER_DOCUMENTS,
				'allowed_states'   => self::PICKING_LIST_STATES,
				'storage_policy'   => DocumentType::STORAGE_PRINT,
				'template_version' => '1',
			),
		);
	}

	/**
	 * Resolved, validated types keyed by id.
	 *
	 * @return array<string, DocumentType>
	 */
	public function all(): array {
		$raw = self::bundled_definitions();

		/**
		 * Filters the document-type registry.
		 *
		 * Integrators may add or amend definitions. Malformed entries are
		 * dropped; unknown shapes never reach DocumentService.
		 *
		 * @since 0.4.0
		 *
		 * @param array<string, array<string, mixed>|DocumentType> $types Bundled definitions keyed by id.
		 */
		$filtered = apply_filters( 'mpcf_document_types', $raw );

		if ( ! is_array( $filtered ) ) {
			$filtered = $raw;
		}

		$types = array();

		foreach ( $filtered as $key => $entry ) {
			$type = $this->normalize_entry( $key, $entry );

			if ( null === $type || ! $type->is_valid() ) {
				continue;
			}

			$types[ $type->id() ] = $type;
		}

		return $types;
	}

	/**
	 * One type by id, or null when unknown/invalid.
	 *
	 * @param string $id Registry key.
	 */
	public function get( string $id ): ?DocumentType {
		$types = $this->all();

		return $types[ $id ] ?? null;
	}

	/**
	 * Normalizes one registry map entry into a DocumentType.
	 *
	 * @param mixed $key   Array key from the filtered map.
	 * @param mixed $entry Definition array or DocumentType.
	 */
	private function normalize_entry( $key, $entry ): ?DocumentType {
		if ( $entry instanceof DocumentType ) {
			return $entry;
		}

		if ( ! is_array( $entry ) ) {
			return null;
		}

		if ( ! isset( $entry['id'] ) && ! isset( $entry['key'] ) && is_string( $key ) ) {
			$entry['id'] = $key;
		}

		$type = DocumentType::define( $entry );

		return $type->is_valid() ? $type : null;
	}
}
