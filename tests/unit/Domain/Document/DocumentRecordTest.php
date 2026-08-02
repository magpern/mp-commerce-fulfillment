<?php
/**
 * Tests for the document generation record.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Document;

use DateTimeImmutable;
use MPCF\Domain\Document\DocumentRecord;
use PHPUnit\Framework\TestCase;

/**
 * Tests for this class.
 */
final class DocumentRecordTest extends TestCase {

	public function test_create_builds_a_record_with_no_id_yet(): void {
		$now    = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$record = DocumentRecord::create( 42, 'packing_slip', '1.0.0', null, 7, $now );

		self::assertNull( $record->id() );
		self::assertSame( 42, $record->fulfillment_id() );
		self::assertSame( 'packing_slip', $record->doc_type() );
		self::assertSame( '1.0.0', $record->template_version() );
		self::assertNull( $record->file_path(), 'Render-to-print records must never carry a stored file path.' );
		self::assertSame( 7, $record->rendered_by() );
		self::assertSame( $now, $record->created_at() );
	}

	public function test_to_array_and_from_array_round_trip(): void {
		$now     = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$record  = DocumentRecord::create( 42, 'packing_slip', '1.0.0', null, 7, $now );
		$rebuilt = DocumentRecord::from_array( array( 'id' => 3 ) + $record->to_array() );

		self::assertSame( 3, $rebuilt->id() );
		self::assertSame( 42, $rebuilt->fulfillment_id() );
		self::assertSame( 'packing_slip', $rebuilt->doc_type() );
		self::assertSame( '1.0.0', $rebuilt->template_version() );
		self::assertSame( 7, $rebuilt->rendered_by() );
	}
}
