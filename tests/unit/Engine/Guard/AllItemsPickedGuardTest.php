<?php
/**
 * Tests for the all-items-picked guard.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Engine\Guard;

use DateTimeImmutable;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Engine\Guard\AllItemsPickedGuard;
use MPCF\Engine\TransitionContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the all-items-picked guard.
 */
final class AllItemsPickedGuardTest extends TestCase {

	private function fulfillment(): Fulfillment {
		return Fulfillment::intake( 1, 'woocommerce', 1, 'standard', 'picking', '#1', 'Jane', 1, new DateTimeImmutable() );
	}

	public function test_satisfied_when_every_item_fully_picked(): void {
		$item = FulfillmentItem::intake( 1, 1, 1, 0, 'SKU', 'Widget', 3 );
		$item->record_picked( 3 );

		$guard = new AllItemsPickedGuard();

		self::assertTrue( $guard->is_satisfied( $this->fulfillment(), new TransitionContext( array( $item ) ) ) );
	}

	public function test_unsatisfied_when_any_item_not_fully_picked(): void {
		$fully_picked = FulfillmentItem::intake( 1, 1, 1, 0, 'SKU-1', 'Widget', 3 );
		$fully_picked->record_picked( 3 );

		$partially_picked = FulfillmentItem::intake( 1, 2, 2, 0, 'SKU-2', 'Gadget', 2 );
		$partially_picked->record_picked( 1 );

		$guard = new AllItemsPickedGuard();

		self::assertFalse( $guard->is_satisfied( $this->fulfillment(), new TransitionContext( array( $fully_picked, $partially_picked ) ) ) );
	}

	public function test_satisfied_when_there_are_no_items(): void {
		$guard = new AllItemsPickedGuard();

		self::assertTrue( $guard->is_satisfied( $this->fulfillment(), new TransitionContext() ) );
	}

	public function test_id_and_unmet_reason(): void {
		$guard = new AllItemsPickedGuard();

		self::assertSame( 'all_items_picked', $guard->id() );
		self::assertNotSame( '', $guard->unmet_reason() );
	}
}
