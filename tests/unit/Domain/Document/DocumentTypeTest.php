<?php
/**
 * Tests for the document-type registry entry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Document;

use MPCF\Domain\Document\DocumentType;
use PHPUnit\Framework\TestCase;

/**
 * Tests for this class.
 */
final class DocumentTypeTest extends TestCase {

	public function test_create_carries_every_field(): void {
		$type = DocumentType::create( 'packing_slip', 'Packing slip', 'A4', 'mpcf_render_documents' );

		self::assertSame( 'packing_slip', $type->id() );
		self::assertSame( 'Packing slip', $type->label() );
		self::assertSame( 'A4', $type->paper_size() );
		self::assertSame( 'mpcf_render_documents', $type->capability() );
	}
}
