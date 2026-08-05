<?php
/**
 * Document print context tests.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Documents;

use DateTimeImmutable;
use MPCF\Documents\DocumentPrintContext;
use MPCF\Domain\Fulfillment;
use PHPUnit\Framework\TestCase;

/**
 * Stage-aware primary print target and action gating.
 */
final class DocumentPrintContextTest extends TestCase {

	public function test_queued_primary_is_picking_list(): void {
		$f = Fulfillment::intake( 1, 'woocommerce', 1, 'standard', 'queued', '#1', 'A', 1, new DateTimeImmutable() );

		self::assertSame( 'picking_list', DocumentPrintContext::primary_doc_type( $f ) );

		$actions = DocumentPrintContext::actions( $f );
		self::assertTrue( $actions[0]['allowed'] );
		self::assertTrue( $actions[0]['primary'] );
		self::assertFalse( $actions[1]['allowed'] );
		self::assertNotSame( '', $actions[1]['message'] );
	}

	public function test_packed_primary_is_packing_slip(): void {
		$f = Fulfillment::intake( 1, 'woocommerce', 1, 'standard', 'packed', '#1', 'A', 1, new DateTimeImmutable() );

		self::assertSame( 'packing_slip', DocumentPrintContext::primary_doc_type( $f ) );

		$actions = DocumentPrintContext::actions( $f );
		self::assertFalse( $actions[0]['allowed'] );
		self::assertTrue( $actions[1]['allowed'] );
		self::assertTrue( $actions[1]['primary'] );
	}

	public function test_cancelled_has_no_primary_and_denies_both(): void {
		$f = Fulfillment::intake( 1, 'woocommerce', 1, 'standard', 'cancelled', '#1', 'A', 1, new DateTimeImmutable() );

		self::assertNull( DocumentPrintContext::primary_doc_type( $f ) );

		foreach ( DocumentPrintContext::actions( $f ) as $action ) {
			self::assertFalse( $action['allowed'] );
			self::assertFalse( $action['primary'] );
		}
	}
}
