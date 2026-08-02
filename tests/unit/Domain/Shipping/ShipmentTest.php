<?php
/**
 * Tests for the shipment aggregate.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Shipping;

use DateTimeImmutable;
use MPCF\Domain\Shipping\Shipment;
use MPCF\Domain\Shipping\TrackingReference;
use PHPUnit\Framework\TestCase;

/**
 * Tests for this class.
 */
final class ShipmentTest extends TestCase {

	public function test_create_builds_a_pending_shipment_with_no_carrier_or_tracking(): void {
		$now      = new DateTimeImmutable( '2026-08-02 10:00:00' );
		$shipment = Shipment::create( 42, $now );

		self::assertNull( $shipment->id() );
		self::assertSame( 42, $shipment->fulfillment_id() );
		self::assertSame( Shipment::STATUS_PENDING, $shipment->status() );
		self::assertSame( '', $shipment->carrier_id() );
		self::assertFalse( $shipment->tracking()->is_present() );
		self::assertFalse( $shipment->is_ready() );
		self::assertFalse( $shipment->has_tracking() );
		self::assertTrue( $shipment->is_deletable() );
		self::assertNull( $shipment->shipped_at() );
		self::assertSame( $now, $shipment->created_at() );
	}

	public function test_is_ready_requires_both_a_carrier_and_tracking(): void {
		$shipment = Shipment::create( 1, new DateTimeImmutable() );

		$shipment->set_carrier( 'postnord', 'MyPack' );
		self::assertFalse( $shipment->is_ready(), 'A carrier alone is not enough.' );

		$shipment->set_tracking( TrackingReference::create( 'ABC123' ) );
		self::assertTrue( $shipment->is_ready() );
		self::assertTrue( $shipment->has_tracking() );
	}

	public function test_mark_shipped_and_mark_delivered_advance_status_and_stamp_timestamps(): void {
		$shipment = Shipment::create( 1, new DateTimeImmutable( '2026-08-01 00:00:00' ) );

		$shipped_at = new DateTimeImmutable( '2026-08-02 09:00:00' );
		$shipment->mark_shipped( $shipped_at );

		self::assertSame( Shipment::STATUS_SHIPPED, $shipment->status() );
		self::assertSame( $shipped_at, $shipment->shipped_at() );
		self::assertFalse( $shipment->is_deletable(), 'A shipped shipment must never be deletable — corrected, never deleted.' );

		$delivered_at = new DateTimeImmutable( '2026-08-03 09:00:00' );
		$shipment->mark_delivered( $delivered_at );

		self::assertSame( Shipment::STATUS_DELIVERED, $shipment->status() );
		self::assertSame( $delivered_at, $shipment->delivered_at() );
	}

	public function test_mark_exception_is_reachable_from_shipped(): void {
		$shipment = Shipment::create( 1, new DateTimeImmutable() );
		$shipment->mark_shipped( new DateTimeImmutable() );

		$shipment->mark_exception();

		self::assertSame( Shipment::STATUS_EXCEPTION, $shipment->status() );
	}

	public function test_to_array_and_from_array_round_trip(): void {
		$now = new DateTimeImmutable( '2026-08-02 10:00:00' );

		$shipment = Shipment::create( 42, $now );
		$shipment->set_carrier( 'postnord', 'MyPack' );
		$shipment->set_tracking( TrackingReference::create( 'ABC123', 'https://track.example/ABC123' ) );
		$shipment->mark_shipped( $now );

		$rebuilt = Shipment::from_array( array( 'id' => 7 ) + $shipment->to_array() );

		self::assertSame( 7, $rebuilt->id() );
		self::assertSame( 42, $rebuilt->fulfillment_id() );
		self::assertSame( 'postnord', $rebuilt->carrier_id() );
		self::assertSame( 'MyPack', $rebuilt->service() );
		self::assertSame( 'ABC123', $rebuilt->tracking()->number() );
		self::assertSame( 'https://track.example/ABC123', $rebuilt->tracking()->url() );
		self::assertSame( Shipment::STATUS_SHIPPED, $rebuilt->status() );
	}
}
