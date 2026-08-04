<?php
/**
 * Integration tests for the Orders admin overview screen.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Admin;

use MPCF\Admin\OrdersPage;
use MPCF\Admin\WorkspacePage;
use MPCF\Application\OrderOverviewService;
use MPCF\Capabilities;
use MPCF\Domain\OrderOverviewQuery;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\Database\WpdbSearchQuery;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use MPCF\Tests\Integration\Woo\OrderFactoryTrait;
use MPCF\Woo\WooOrderSource;
use WP_UnitTestCase;

/**
 * Proves OrdersPage renders HPOS-safe Woo + fulfillment association without
 * creating fulfillments for unpaid orders, and that Open destinations are
 * correct.
 */
final class OrdersPageTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;
	use OrderFactoryTrait;

	/**
	 * @var OrdersPage
	 */
	private OrdersPage $page;

	/**
	 * @var WpdbFulfillmentRepository
	 */
	private WpdbFulfillmentRepository $fulfillments;

	protected function setUp(): void {
		parent::setUp();
		$this->clean_fulfillment_tables();
		\MPCF\Plugin::activate();

		$this->fulfillments = new WpdbFulfillmentRepository();
		$this->page         = new OrdersPage(
			new \MPCF\Vendor\Mpds\PageShell\AdminPageShell( new \MPCF\Vendor\Mpds\PageShell\SectionNavigation() ),
			new \MPCF\Vendor\Mpds\ComponentRenderer(),
			new OrderOverviewService( new WooOrderSource(), $this->fulfillments, new WpdbSearchQuery() )
		);
	}

	public function test_capability_is_view_queue(): void {
		self::assertSame( Capabilities::VIEW_QUEUE, $this->page->capability() );
	}

	public function test_pending_payment_order_has_no_fulfillment_and_opens_woocommerce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$order = $this->create_unpaid_order();

		$_GET['page']   = OrdersPage::SLUG;
		$_GET['filter'] = OrderOverviewQuery::FILTER_AWAITING_PAYMENT;

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( (string) $order->get_order_number(), $html );
		self::assertStringContainsString( 'Awaiting payment', $html );
		self::assertStringContainsString( 'page=wc-orders', $html );
		self::assertStringContainsString( 'id=' . $order->get_id(), $html );
		self::assertStringNotContainsString( 'fulfillment_id=', $html );
		self::assertNull( $this->fulfillments->find_by_order_id( $order->get_id() ), 'Orders view must not create a fulfillment for pending payment.' );
	}

	public function test_processing_fulfillment_opens_workspace_with_continue_picking(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$order       = $this->create_paid_order( 1 );
		$fulfillment = $this->fulfillments->find_by_order_id( $order->get_id() );
		self::assertNotNull( $fulfillment );

		$fulfillment->apply_transition( 'picking', null, new \DateTimeImmutable( '2026-08-04 10:00:00' ) );
		self::assertTrue( $this->fulfillments->save( $fulfillment ) );

		$_GET['page']   = OrdersPage::SLUG;
		$_GET['filter'] = OrderOverviewQuery::FILTER_WAREHOUSE_ACTIVE;

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( (string) $order->get_order_number(), $html );
		self::assertStringContainsString( 'Continue picking', $html );
		self::assertStringContainsString( 'page=' . WorkspacePage::SLUG, $html );
		self::assertStringContainsString( 'fulfillment_id=' . $fulfillment->id(), $html );
		self::assertStringContainsString( 'Open Workspace', $html );
	}

	public function test_cancelled_order_shows_no_action(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$order = $this->create_unpaid_order();
		$order->update_status( 'cancelled' );

		$_GET['page']   = OrdersPage::SLUG;
		$_GET['filter'] = OrderOverviewQuery::FILTER_CANCELLED;

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( (string) $order->get_order_number(), $html );
		self::assertStringContainsString( 'No action', $html );
	}

	public function test_filter_bar_exposes_lightweight_presets_and_search(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Capabilities::ROLE_OPERATOR ) ) );

		$_GET['page'] = OrdersPage::SLUG;

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="filter"', $html );
		self::assertStringContainsString( 'needs_attention', $html );
		self::assertStringContainsString( 'warehouse_active', $html );
		self::assertStringContainsString( 'awaiting_payment', $html );
		self::assertStringContainsString( 'name="s"', $html );
		self::assertStringContainsString( 'data-mpcf-search-focus', $html );
	}

	/**
	 * Creates a pending-payment order that must not trigger intake.
	 */
	private function create_unpaid_order(): \WC_Order {
		$product = $this->create_product();

		$order = new \WC_Order();
		$order->add_product( $product, 1 );
		$order->set_billing_first_name( 'Pat' );
		$order->set_billing_last_name( 'Pending' );
		$order->set_status( 'pending' );
		$order->calculate_totals();
		$order->save();

		return $order;
	}
}
