<?php
/**
 * Tests for the document-type registry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Documents;

use MPCF\Documents\DocumentTypeRegistry;
use MPCF\Domain\Document\DocumentType;
use PHPUnit\Framework\TestCase;

/**
 * Registry contains the two approved keys and rejects bad filter input.
 */
final class DocumentTypeRegistryTest extends TestCase {

	protected function tearDown(): void {
		remove_all_filters( 'mpcf_document_types' );
		parent::tearDown();
	}

	public function test_registry_contains_the_two_approved_core_type_keys(): void {
		$types = ( new DocumentTypeRegistry() )->all();

		self::assertArrayHasKey( 'packing_slip', $types );
		self::assertArrayHasKey( 'picking_list', $types );
		self::assertCount( 2, $types );
	}

	public function test_packing_slip_definition_is_valid(): void {
		$type = ( new DocumentTypeRegistry() )->get( 'packing_slip' );

		self::assertNotNull( $type );
		self::assertTrue( $type->is_valid() );
		self::assertSame( 'packing_slip', $type->assembler() );
		self::assertSame( DocumentType::RENDERER_HTML, $type->renderer() );
		self::assertSame( DocumentType::STORAGE_STORE, $type->storage_policy() );
		self::assertSame( '2', $type->template_version() );
		self::assertSame( DocumentTypeRegistry::PACKING_SLIP_STATES, $type->allowed_states() );
	}

	public function test_invalid_type_key_returns_null(): void {
		self::assertNull( ( new DocumentTypeRegistry() )->get( 'delivery_note' ) );
		self::assertNull( ( new DocumentTypeRegistry() )->get( '' ) );
	}

	public function test_malformed_filtered_definition_is_rejected(): void {
		add_filter(
			'mpcf_document_types',
			static function ( array $types ): array {
				$types['broken'] = array(
					'id'    => '!!!',
					'label' => '',
				);

				return $types;
			}
		);

		$types = ( new DocumentTypeRegistry() )->all();

		self::assertArrayNotHasKey( 'broken', $types );
		self::assertArrayHasKey( 'packing_slip', $types );
	}

	public function test_filter_may_amend_a_valid_definition(): void {
		add_filter(
			'mpcf_document_types',
			static function ( array $types ): array {
				$types['packing_slip']['label'] = 'Custom packing slip';

				return $types;
			}
		);

		$type = ( new DocumentTypeRegistry() )->get( 'packing_slip' );

		self::assertNotNull( $type );
		self::assertSame( 'Custom packing slip', $type->label() );
	}
}
