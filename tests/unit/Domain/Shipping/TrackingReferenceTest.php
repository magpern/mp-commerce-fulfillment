<?php
/**
 * Tests for the tracking-reference value object.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Domain\Shipping;

use MPCF\Domain\Shipping\TrackingReference;
use PHPUnit\Framework\TestCase;

/**
 * Tests for this class.
 */
final class TrackingReferenceTest extends TestCase {

	public function test_none_is_not_present(): void {
		$tracking = TrackingReference::none();

		self::assertFalse( $tracking->is_present() );
		self::assertNull( $tracking->number() );
		self::assertNull( $tracking->url() );
	}

	public function test_create_with_number_and_url(): void {
		$tracking = TrackingReference::create( 'ABC123', 'https://example.test/track/ABC123' );

		self::assertTrue( $tracking->is_present() );
		self::assertSame( 'ABC123', $tracking->number() );
		self::assertSame( 'https://example.test/track/ABC123', $tracking->url() );
	}

	public function test_empty_string_number_is_treated_as_absent(): void {
		$tracking = TrackingReference::create( '', '' );

		self::assertFalse( $tracking->is_present() );
		self::assertNull( $tracking->number() );
		self::assertNull( $tracking->url() );
	}

	public function test_create_with_number_only_leaves_url_null(): void {
		$tracking = TrackingReference::create( 'ABC123' );

		self::assertTrue( $tracking->is_present() );
		self::assertNull( $tracking->url() );
	}
}
