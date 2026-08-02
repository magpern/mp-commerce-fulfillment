<?php
/**
 * Tests for the context-flag guards: package spec, shipment, tracking, and
 * photo requirement.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Engine\Guard;

use DateTimeImmutable;
use MPCF\Domain\Fulfillment;
use MPCF\Engine\Guard\HasShipmentGuard;
use MPCF\Engine\Guard\HasTrackingGuard;
use MPCF\Engine\Guard\PackageSpecPresentGuard;
use MPCF\Engine\Guard\PhotoRequiredGuard;
use MPCF\Engine\TransitionContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the context-flag guards: package spec, shipment, tracking, and
 * photo requirement.
 */
final class ContextFlagGuardsTest extends TestCase {

	private function fulfillment(): Fulfillment {
		return Fulfillment::intake( 1, 'woocommerce', 1, 'standard', 'packing', '#1', 'Jane', 1, new DateTimeImmutable() );
	}

	public function test_package_spec_present_guard_reads_the_context_flag(): void {
		$guard = new PackageSpecPresentGuard();

		self::assertSame( 'package_spec_present', $guard->id() );
		self::assertFalse( $guard->is_satisfied( $this->fulfillment(), new TransitionContext() ) );
		self::assertTrue( $guard->is_satisfied( $this->fulfillment(), new TransitionContext( array(), true ) ) );
	}

	public function test_has_shipment_guard_reads_the_context_flag(): void {
		$guard = new HasShipmentGuard();

		self::assertSame( 'has_shipment', $guard->id() );
		self::assertFalse( $guard->is_satisfied( $this->fulfillment(), new TransitionContext() ) );
		self::assertTrue( $guard->is_satisfied( $this->fulfillment(), new TransitionContext( array(), false, true ) ) );
	}

	public function test_photo_required_guard_defaults_to_satisfied(): void {
		$guard = new PhotoRequiredGuard();

		self::assertSame( 'photo_required', $guard->id() );
		self::assertTrue( $guard->is_satisfied( $this->fulfillment(), new TransitionContext() ) );
		self::assertFalse( $guard->is_satisfied( $this->fulfillment(), new TransitionContext( array(), false, false, false ) ) );
	}

	public function test_has_tracking_guard_defaults_to_satisfied(): void {
		$guard = new HasTrackingGuard();

		self::assertSame( 'has_tracking', $guard->id() );
		self::assertTrue( $guard->is_satisfied( $this->fulfillment(), new TransitionContext() ) );
		self::assertFalse( $guard->is_satisfied( $this->fulfillment(), new TransitionContext( array(), false, false, true, false ) ) );
	}
}
