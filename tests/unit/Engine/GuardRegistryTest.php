<?php
/**
 * Tests for the guard-id-to-implementation registry.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Engine;

use InvalidArgumentException;
use MPCF\Engine\Guard\AllItemsPickedGuard;
use MPCF\Engine\GuardRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the guard-id-to-implementation registry.
 */
final class GuardRegistryTest extends TestCase {

	public function test_standard_registers_every_documented_guard(): void {
		$registry = GuardRegistry::standard();

		foreach ( array( 'all_items_picked', 'all_items_packed', 'package_spec_present', 'has_shipment', 'photo_required' ) as $id ) {
			self::assertTrue( $registry->has( $id ), "Expected guard \"{$id}\" to be registered." );
		}
	}

	public function test_get_returns_the_guard_matching_its_own_id(): void {
		$registry = new GuardRegistry( array( new AllItemsPickedGuard() ) );

		self::assertSame( 'all_items_picked', $registry->get( 'all_items_picked' )->id() );
	}

	public function test_get_throws_for_an_unknown_guard_id(): void {
		$registry = new GuardRegistry( array() );

		$this->expectException( InvalidArgumentException::class );

		$registry->get( 'not_a_real_guard' );
	}

	public function test_has_is_false_for_an_unknown_guard_id(): void {
		$registry = new GuardRegistry( array() );

		self::assertFalse( $registry->has( 'not_a_real_guard' ) );
	}
}
