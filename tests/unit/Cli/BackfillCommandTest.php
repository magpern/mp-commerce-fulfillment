<?php
/**
 * Tests for the CLI backfill command's testable core.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit\Cli;

use DateTimeImmutable;
use MPCF\Application\EventDispatcher;
use MPCF\Application\IntakeService;
use MPCF\Cli\BackfillCommand;
use MPCF\Domain\OrderLineSnapshot;
use MPCF\Domain\OrderSnapshot;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Tests\Unit\Application\Doubles\FixedClock;
use MPCF\Tests\Unit\Application\Doubles\InMemoryEventRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentItemRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryFulfillmentRepository;
use MPCF\Tests\Unit\Application\Doubles\InMemoryOrderSource;
use PHPUnit\Framework\TestCase;

/**
 * `run_backfill()` is the command's entire real logic — no WP-CLI runtime
 * involved — so it is exercised directly here, the same way
 * `IntakeServiceTest` exercises `IntakeService` itself.
 */
final class BackfillCommandTest extends TestCase {

	/**
	 * @var InMemoryOrderSource
	 */
	private InMemoryOrderSource $orders;

	/**
	 * @var BackfillCommand
	 */
	private BackfillCommand $command;

	protected function setUp(): void {
		$this->orders = new InMemoryOrderSource();

		$intake = new IntakeService(
			$this->orders,
			new InMemoryFulfillmentRepository(),
			new InMemoryFulfillmentItemRepository(),
			new InMemoryEventRepository(),
			new EventDispatcher(),
			new FixedClock( new DateTimeImmutable( '2026-08-02 10:00:00' ) ),
			StandardWorkflow::definition()
		);

		$this->command = new BackfillCommand( $this->orders, $intake );
	}

	private function seed_order( int $order_id, string $status ): void {
		$this->orders->seed(
			OrderSnapshot::create(
				$order_id,
				'woocommerce',
				'#' . $order_id,
				'Jane Doe',
				$status,
				array( OrderLineSnapshot::create( 501, 900, 0, 'SKU-1', 'Widget', 1 ) )
			)
		);
	}

	public function test_run_backfill_creates_a_fulfillment_for_every_matching_order(): void {
		$this->seed_order( 1001, 'processing' );
		$this->seed_order( 1002, 'processing' );
		$this->seed_order( 1003, 'pending' ); // Different status — must not be inspected.

		$result = $this->command->run_backfill( 'processing' );

		self::assertSame( 2, $result['inspected'] );
		self::assertSame( 2, $result['created'] );
		self::assertSame( 0, $result['already_ingested'] );
		self::assertSame( 0, $result['failed'] );
		self::assertSame( array(), $result['failed_order_ids'] );
	}

	public function test_run_backfill_is_idempotent_across_repeated_runs(): void {
		$this->seed_order( 1001, 'processing' );
		$this->seed_order( 1002, 'processing' );

		$first  = $this->command->run_backfill( 'processing' );
		$second = $this->command->run_backfill( 'processing' );

		self::assertSame( 2, $first['created'] );
		self::assertSame( 0, $first['already_ingested'] );

		self::assertSame( 2, $second['inspected'], 'A repeated run must still inspect the same orders.' );
		self::assertSame( 0, $second['created'], 'A repeated run must create no new fulfillments.' );
		self::assertSame( 2, $second['already_ingested'], 'A repeated run must report every order as already ingested.' );
	}

	public function test_run_backfill_reports_zero_counts_when_nothing_matches(): void {
		$result = $this->command->run_backfill( 'processing' );

		self::assertSame( 0, $result['inspected'] );
		self::assertSame( 0, $result['created'] );
		self::assertSame( 0, $result['already_ingested'] );
		self::assertSame( 0, $result['failed'] );
	}
}
