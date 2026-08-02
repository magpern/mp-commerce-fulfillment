<?php
/**
 * Tests for the all-items-packed guard.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Engine\Guard;

use DateTimeImmutable;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Engine\Guard\AllItemsPackedGuard;
use MPCF\Engine\TransitionContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the all-items-packed guard.
 */
final class AllItemsPackedGuardTest extends TestCase {

	private function fulfillment(): Fulfillment {
		return Fulfillment::intake( 1, 'woocommerce', 1, 'standard', 'packing', '#1', 'Jane', 1, new DateTimeImmutable() );
	}

	public function test_satisfied_when_every_item_fully_packed(): void {
		$item = FulfillmentItem::intake( 1, 1, 1, 0, 'SKU', 'Widget', 3 );
		$item->record_packed( 3 );

		$guard = new AllItemsPackedGuard();

		self::assertTrue( $guard->is_satisfied( $this->fulfillment(), new TransitionContext( array( $item ) ) ) );
	}

	public function test_unsatisfied_when_any_item_not_fully_packed(): void {
		$item = FulfillmentItem::intake( 1, 1, 1, 0, 'SKU', 'Widget', 3 );
		$item->record_packed( 2 );

		$guard = new AllItemsPackedGuard();

		self::assertFalse( $guard->is_satisfied( $this->fulfillment(), new TransitionContext( array( $item ) ) ) );
	}

	public function test_id_and_unmet_reason(): void {
		$guard = new AllItemsPackedGuard();

		self::assertSame( 'all_items_packed', $guard->id() );
		self::assertNotSame( '', $guard->unmet_reason() );
	}
}
