<?php
/**
 * Integration tests for the CLI backfill command against real orders.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Cli;

use DateTimeImmutable;
use MPCF\Application\EventDispatcher;
use MPCF\Application\IntakeService;
use MPCF\Cli\BackfillCommand;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\SystemClock;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Tests\Integration\Woo\OrderFactoryTrait;
use MPCF\Woo\WooOrderSource;
use WP_UnitTestCase;

/**
 * `run_backfill()` never touches the WP-CLI runtime, so it can be exercised
 * here against a real WooCommerce order/HPOS install exactly as
 * `wp mpcf intake backfill` would — the only piece these tests cannot cover
 * (registering the command's WP_CLI::add_command() call and its printed
 * output) has no logic of its own to verify; see the class docblock in
 * BackfillCommand.
 */
final class BackfillCommandTest extends WP_UnitTestCase {

	use OrderFactoryTrait;
	use CleanFulfillmentTablesTrait;

	/**
	 * @var BackfillCommand
	 */
	private BackfillCommand $command;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();

		// These tests exist to prove the backfill command itself discovers
		// and ingests real orders — not to re-prove the live hooks do (see
		// IntakeHooksTest for that). Removing them isolates the two
		// concerns; WP_UnitTestCase's own setUp/tearDown snapshot and
		// restore every hook automatically, so nothing here leaks into
		// other test classes.
		remove_all_actions( 'woocommerce_payment_complete' );
		remove_all_actions( 'woocommerce_order_status_processing' );

		$orders = new WooOrderSource();

		$intake = new IntakeService(
			$orders,
			new WpdbFulfillmentRepository(),
			new WpdbFulfillmentItemRepository(),
			new WpdbEventRepository(),
			new EventDispatcher(),
			new SystemClock(),
			StandardWorkflow::definition()
		);

		$this->command = new BackfillCommand( $orders, $intake );
	}

	public function test_backfill_ingests_every_real_order_in_the_given_status(): void {
		$first  = $this->create_paid_order();
		$second = $this->create_paid_order();

		$result = $this->command->run_backfill( 'processing' );

		self::assertSame( 2, $result['inspected'] );
		self::assertSame( 2, $result['created'] );
		self::assertSame( 0, $result['already_ingested'] );
		self::assertSame( 0, $result['failed'] );

		$repository = new WpdbFulfillmentRepository();
		self::assertNotNull( $repository->find_by_order_id( $first->get_id() ) );
		self::assertNotNull( $repository->find_by_order_id( $second->get_id() ) );
	}

	public function test_repeated_backfills_create_no_duplicates(): void {
		$this->create_paid_order();
		$this->create_paid_order();

		$this->command->run_backfill( 'processing' );
		$this->command->run_backfill( 'processing' );
		$result = $this->command->run_backfill( 'processing' );

		self::assertSame( 2, $result['inspected'] );
		self::assertSame( 0, $result['created'], 'By the third run every order is already ingested.' );
		self::assertSame( 2, $result['already_ingested'] );
	}

	public function test_backfill_ignores_orders_in_a_different_status(): void {
		$order = new \WC_Order();
		$order->add_product( $this->create_product(), 1 );
		$order->set_status( 'on-hold' );
		$order->calculate_totals();
		$order->save();

		$result = $this->command->run_backfill( 'processing' );

		self::assertSame( 0, $result['inspected'] );
	}
}
