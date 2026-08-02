<?php
/**
 * Integration tests for the fulfillment item repository against a real
 * database.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Infrastructure;

use DateTimeImmutable;
use MPCF\Domain\Fulfillment;
use MPCF\Domain\FulfillmentItem;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use WP_UnitTestCase;

/**
 * Integration tests for the fulfillment item repository against a real
 * database.
 */
final class WpdbFulfillmentItemRepositoryTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;

	/**
	 * Repository under test.
	 *
	 * @var WpdbFulfillmentItemRepository
	 */
	private WpdbFulfillmentItemRepository $repository;

	/**
	 * Owning fulfillment's id, created fresh per test.
	 *
	 * @var int
	 */
	private int $fulfillment_id;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables(); // Ensures the tables exist and are empty; see the trait's docblock.
		$this->repository     = new WpdbFulfillmentItemRepository();
		$this->fulfillment_id = ( new WpdbFulfillmentRepository() )->insert(
			Fulfillment::intake( 1001, 'woocommerce', 1, 'standard', 'queued', '#1001', 'Jane Doe', 2, new DateTimeImmutable() )
		);
	}

	public function test_insert_all_and_find_for_fulfillment_round_trip(): void {
		$this->repository->insert_all(
			array(
				FulfillmentItem::intake( $this->fulfillment_id, 501, 900, 0, 'SKU-1', 'Widget', 3 ),
				FulfillmentItem::intake( $this->fulfillment_id, 502, 901, 5, 'SKU-2', 'Gadget', 1 ),
			)
		);

		$items = $this->repository->find_for_fulfillment( $this->fulfillment_id );

		self::assertCount( 2, $items );
		self::assertSame( 'SKU-1', $items[0]->sku_snapshot() );
		self::assertSame( 3, $items[0]->qty_ordered() );
		self::assertSame( 0, $items[0]->qty_picked() );
		self::assertSame( 'SKU-2', $items[1]->sku_snapshot() );
		self::assertSame( 5, $items[1]->variation_id() );
	}

	public function test_find_for_fulfillment_returns_an_empty_list_when_none_exist(): void {
		self::assertSame( array(), $this->repository->find_for_fulfillment( $this->fulfillment_id ) );
	}

	public function test_save_persists_picked_and_packed_quantities(): void {
		$this->repository->insert_all( array( FulfillmentItem::intake( $this->fulfillment_id, 501, 900, 0, 'SKU-1', 'Widget', 3 ) ) );

		$item = $this->repository->find_for_fulfillment( $this->fulfillment_id )[0];
		$item->record_picked( 3 );
		$item->record_packed( 2 );

		$this->repository->save( $item );

		$reloaded = $this->repository->find_for_fulfillment( $this->fulfillment_id )[0];

		self::assertSame( 3, $reloaded->qty_picked() );
		self::assertSame( 2, $reloaded->qty_packed() );
	}

	public function test_find_for_fulfillment_does_not_return_items_belonging_to_another_fulfillment(): void {
		$other_fulfillment_id = ( new WpdbFulfillmentRepository() )->insert(
			Fulfillment::intake( 1002, 'woocommerce', 1, 'standard', 'queued', '#1002', 'John Doe', 1, new DateTimeImmutable() )
		);

		$this->repository->insert_all( array( FulfillmentItem::intake( $this->fulfillment_id, 501, 900, 0, 'SKU-1', 'Widget', 3 ) ) );
		$this->repository->insert_all( array( FulfillmentItem::intake( $other_fulfillment_id, 601, 950, 0, 'SKU-9', 'Other', 1 ) ) );

		$items = $this->repository->find_for_fulfillment( $this->fulfillment_id );

		self::assertCount( 1, $items );
		self::assertSame( 'SKU-1', $items[0]->sku_snapshot() );
	}
}
