<?php
/**
 * Composition root.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF;

use MPCF\Admin\Assets;
use MPCF\Admin\DashboardPage;
use MPCF\Admin\FulfillmentDetailPage;
use MPCF\Admin\OperatorMode;
use MPCF\Admin\QueuePage;
use MPCF\Application\AssignmentService;
use MPCF\Application\DashboardService;
use MPCF\Application\EventDispatcher;
use MPCF\Application\FulfillmentDetailService;
use MPCF\Application\IntakeService;
use MPCF\Application\NoteService;
use MPCF\Application\QueueService;
use MPCF\Application\WorkflowService;
use MPCF\Cli\BackfillCommand;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Engine\GuardRegistry;
use MPCF\Engine\WorkflowEngine;
use MPCF\Infrastructure\Database\Migrator;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\Database\WpdbNoteRepository;
use MPCF\Infrastructure\Database\WpdbSearchQuery;
use MPCF\Infrastructure\SystemClock;
use MPCF\Vendor\Mpds\ComponentRenderer;
use MPCF\Vendor\Mpds\PageShell\AdminPageShell;
use MPCF\Vendor\Mpds\PageShell\Menu;
use MPCF\Vendor\Mpds\PageShell\SectionNavigation;
use MPCF\Woo\IntakeHooks;
use MPCF\Woo\RefundObserver;
use MPCF\Woo\StatusBridge;
use MPCF\Woo\WooOrderSource;

/**
 * Wires the object graph by hand and lets each service register its own
 * hooks. There is no container (house convention, see the sibling plugins'
 * `Plugin` classes) — no internal service may instantiate a peer; every
 * dependency is constructor-injected from here.
 *
 * Milestone 0 wired nothing beyond the textdomain and the migration
 * drift-check. Milestone 1 builds the plugin's real service graph across
 * two methods: {@see wire_services()} (repositories, the workflow engine,
 * the shared event dispatcher, intake, the status bridge, the inbound
 * observer, and — only under WP-CLI — the backfill command) always, and
 * {@see wire_admin()} (the Fulfillment menu and its three screens) only
 * when `is_admin()`. Every collaborator is constructed here, by name, and
 * handed to whatever needs it next; none is stored as a property (see
 * `CompositionRootTest::test_plugin_declares_only_singleton_bookkeeping_properties()`),
 * so this class still owns no service-holding state of its own.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether services have been wired.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Returns the shared plugin instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wires services and registers hooks. Idempotent.
	 */
	public function init(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'mp-commerce-fulfillment', false, dirname( plugin_basename( MPCF_PLUGIN_FILE ) ) . '/languages' );
			}
		);

		// Bind-mount deployments update files in place and never fire the
		// activation hook, so schema drift has to be caught on its own (see
		// Infrastructure\Database\Migrator).
		add_action(
			'admin_init',
			static function () {
				( new Migrator() )->maybe_migrate();
			}
		);

		$this->wire_services();

		// Architecture Plan §5.4: menu/screens/assets are gated to is_admin()
		// contexts only — a front-end or WP-CLI request never needs them.
		if ( is_admin() ) {
			$this->wire_admin();
		}
	}

	/**
	 * Creates/updates the schema and grants capabilities/roles.
	 */
	public static function activate(): void {
		( new Migrator() )->migrate();

		Capabilities::activate();
	}

	/**
	 * Builds every Milestone 1 service and registers its platform hooks.
	 * Runs unconditionally from `init()` (itself gated on the order
	 * platform being active by the main plugin file) — registering the
	 * hooks here, rather than deferring to a later action, is what makes
	 * them present in time for the same request's checkout/order-admin
	 * processing to fire them.
	 */
	private function wire_services(): void {
		$fulfillments = new WpdbFulfillmentRepository();
		$items        = new WpdbFulfillmentItemRepository();
		$events       = new WpdbEventRepository();
		$dispatcher   = new EventDispatcher();
		$clock        = new SystemClock();
		$orders       = new WooOrderSource();
		$settings     = new Settings();

		$workflow_service = new WorkflowService(
			$fulfillments,
			$items,
			$events,
			new WorkflowEngine( GuardRegistry::standard() ),
			$dispatcher,
			$clock,
			array( StandardWorkflow::NAME => StandardWorkflow::definition() )
		);

		$intake = new IntakeService(
			$orders,
			$fulfillments,
			$items,
			$events,
			$dispatcher,
			$clock,
			StandardWorkflow::definition()
		);

		( new IntakeHooks( $intake ) )->register();

		// The outbound bridge is just another subscriber on the same event
		// bus intake uses (invariant I4's single-writer rule already
		// guarantees WorkflowService is the only thing that can ever
		// dispatch a fulfillment.state_changed event for it to react to).
		$dispatcher->subscribe( 'fulfillment.state_changed', new StatusBridge( $fulfillments, $settings ) );

		( new RefundObserver( $fulfillments, $items, $orders, $workflow_service, $settings ) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new BackfillCommand( $orders, $intake ) )->register();
		}
	}

	/**
	 * Builds Milestone 1's admin screens (D15-D18: Queue, Fulfillment
	 * Detail, Dashboard) and registers the Fulfillment menu. Fulfillment
	 * Detail is registered as a real submenu page (so its capability check
	 * and URL both work) and then immediately removed from the visible
	 * menu — it is reached from the Queue/Dashboard, never a standalone nav
	 * destination (Architecture Plan §9.3).
	 */
	private function wire_admin(): void {
		$fulfillments = new WpdbFulfillmentRepository();
		$items        = new WpdbFulfillmentItemRepository();
		$events       = new WpdbEventRepository();
		$notes        = new WpdbNoteRepository();
		$clock        = new SystemClock();
		$settings     = new Settings();
		$definition   = StandardWorkflow::definition();

		$workflow_service = new WorkflowService(
			$fulfillments,
			$items,
			$events,
			new WorkflowEngine( GuardRegistry::standard() ),
			new EventDispatcher(),
			$clock,
			array( StandardWorkflow::NAME => $definition )
		);

		$renderer = new ComponentRenderer();
		$shell    = new AdminPageShell( new SectionNavigation() );

		$queue_service  = new QueueService( $fulfillments, new WpdbSearchQuery() );
		$detail_service = new FulfillmentDetailService( $fulfillments, $items, $events, $notes );
		$note_service   = new NoteService( $notes, $clock );
		$assignments    = new AssignmentService( $fulfillments );
		$dashboard      = new DashboardService( $fulfillments, $events, $clock );

		$dashboard_page = new DashboardPage( $shell, $renderer, $dashboard, $definition );
		$queue_page     = new QueuePage( $shell, $renderer, $queue_service, $detail_service, $assignments, $workflow_service, $definition );
		$detail_page    = new FulfillmentDetailPage( $shell, $renderer, $detail_service, $note_service, $workflow_service, $definition );

		( new Menu(
			DashboardPage::SLUG,
			__( 'Fulfillment', 'mp-commerce-fulfillment' ),
			'dashicons-archive',
			Capabilities::VIEW_QUEUE,
			array( $dashboard_page, $queue_page )
		) )->register();

		add_action(
			'admin_menu',
			static function () use ( $detail_page ) {
				add_submenu_page(
					DashboardPage::SLUG,
					$detail_page->title(),
					$detail_page->menu_title(),
					$detail_page->capability(),
					$detail_page->slug(),
					array( $detail_page, 'render' )
				);
				remove_submenu_page( DashboardPage::SLUG, $detail_page->slug() );
			},
			20
		);

		( new Assets() )->register();
		( new OperatorMode( $settings ) )->register();
	}
}
