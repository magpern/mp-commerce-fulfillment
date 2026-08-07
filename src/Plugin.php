<?php
/**
 * Composition root.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF;

use MPCF\Admin\Assets;
use MPCF\Admin\AnalyticsPage;
use MPCF\Admin\DashboardPage;
use MPCF\Admin\DocumentsPage;
use MPCF\Admin\FulfillmentDetailPage;
use MPCF\Admin\OperatorMode;
use MPCF\Admin\OrdersPage;
use MPCF\Admin\QueuePage;
use MPCF\Admin\SettingsPage;
use MPCF\Admin\WavePage;
use MPCF\Admin\WorkspacePage;
use MPCF\Application\Analytics\AnalyticsCsvExporter;
use MPCF\Application\Analytics\AnalyticsService;
use MPCF\Application\DocumentHistoryService;
use MPCF\Application\DocumentService;
use MPCF\Application\Notifications\NotificationConfigurationService;
use MPCF\Application\Notifications\NotificationDispatcher;
use MPCF\Application\Notifications\NotificationFactory;
use MPCF\Application\Notifications\NotificationService;
use MPCF\Api\Rest\AnalyticsController;
use MPCF\Api\Rest\AssignmentController;
use MPCF\Api\Rest\CarriersController;
use MPCF\Api\Rest\DocumentsController;
use MPCF\Api\Rest\FulfillmentsController;
use MPCF\Api\Rest\ItemsController;
use MPCF\Api\Rest\NotesController;
use MPCF\Api\Rest\NotificationsController;
use MPCF\Api\Rest\PackagesController;
use MPCF\Api\Rest\PhotosController;
use MPCF\Api\Rest\ScanController;
use MPCF\Api\Rest\RestApi;
use MPCF\Api\Rest\ShipmentsController;
use MPCF\Api\Rest\WavesController;
use MPCF\Application\AssignmentService;
use MPCF\Application\DashboardService;
use MPCF\Application\EventDispatcher;
use MPCF\Application\FulfillmentDetailService;
use MPCF\Application\IntakeService;
use MPCF\Application\NoteService;
use MPCF\Application\OrderOverviewService;
use MPCF\Application\PackingService;
use MPCF\Application\Photos\PhotoConfig;
use MPCF\Application\Photos\PhotoRetentionService;
use MPCF\Application\Photos\PhotoService;
use MPCF\Application\QueueService;
use MPCF\Application\Scan\ScanService;
use MPCF\Application\ShipmentAutoShipSubscriber;
use MPCF\Application\ShippingService;
use MPCF\Application\TransitionContextFactory;
use MPCF\Application\Wave\WaveScanService;
use MPCF\Application\Wave\WaveService;
use MPCF\Application\WorkflowService;
use MPCF\Application\Diagnostics\AuditChainVerifier;
use MPCF\Infrastructure\Diagnostics\DefaultCheckerRegistryFactory;
use MPCF\Application\Diagnostics\DoctorService;
use MPCF\Application\Diagnostics\MaintenanceAuditor;
use MPCF\Infrastructure\Diagnostics\Repair\CapabilitiesRepairService;
use MPCF\Infrastructure\Diagnostics\Repair\ScheduleRepairService;
use MPCF\Infrastructure\Diagnostics\Repair\SchemaRepairService;
use MPCF\Infrastructure\Diagnostics\Repair\StorageDirsRepairService;
use MPCF\Application\Diagnostics\ValidationService;
use MPCF\Cli\AnalyticsCommand;
use MPCF\Cli\AuditCommand;
use MPCF\Cli\BackfillCommand;
use MPCF\Cli\DoctorCommand;
use MPCF\Cli\RepairCommand;
use MPCF\Cli\ValidateCommand;
use MPCF\Documents\HtmlRenderer;
use MPCF\Documents\TemplateRegistry;
use MPCF\Domain\Scan\ScanResolver;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Domain\Workflow\WorkflowDefinition;
use MPCF\Engine\Analytics\AnalyticsEngine;
use MPCF\Engine\GuardRegistry;
use MPCF\Engine\WorkflowEngine;
use MPCF\Infrastructure\Carriers\BundledCarrierRegistry;
use MPCF\Infrastructure\Database\Migrator;
use MPCF\Infrastructure\Database\WpdbAnalyticsDailyRepository;
use MPCF\Infrastructure\Database\WpdbAnalyticsDiagnosticsReader;
use MPCF\Infrastructure\Database\WpdbAnalyticsEventSource;
use MPCF\Infrastructure\Database\WpdbDocumentRepository;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\Database\WpdbMediaRepository;
use MPCF\Infrastructure\Database\WpdbNoteRepository;
use MPCF\Infrastructure\Database\WpdbPackageItemRepository;
use MPCF\Infrastructure\Database\WpdbPackageRepository;
use MPCF\Infrastructure\Database\WpdbSearchQuery;
use MPCF\Infrastructure\Database\WpdbShipmentRepository;
use MPCF\Infrastructure\Database\WpdbWaveRepository;
use MPCF\Infrastructure\Files\ProtectedDocumentStore;
use MPCF\Infrastructure\Files\ProtectedPhotoStore;
use MPCF\Infrastructure\Media\GdImageProcessor;
use MPCF\Infrastructure\Notifications\EmailChannel;
use MPCF\Infrastructure\Privacy\PrivacyEraser;
use MPCF\Infrastructure\Privacy\PrivacyExporter;
use MPCF\Infrastructure\Privacy\PrivacyRegistrar;
use MPCF\Infrastructure\Scheduling\AnalyticsRollupScheduler;
use MPCF\Infrastructure\Scheduling\PhotoRetentionScheduler;
use MPCF\Infrastructure\Scan\TransientScanCorrectionStore;
use MPCF\Infrastructure\SiteHealth\SiteHealthRegistrar;
use MPCF\Infrastructure\SystemClock;
use MPCF\Vendor\Mpds\ComponentRenderer;
use MPCF\Vendor\Mpds\PageShell\AdminPageShell;
use MPCF\Vendor\Mpds\PageShell\Menu;
use MPCF\Vendor\Mpds\PageShell\SectionNavigation;
use MPCF\Woo\IntakeHooks;
use MPCF\Woo\PrivacyHooks;
use MPCF\Woo\RefundObserver;
use MPCF\Woo\StatusBridge;
use MPCF\Woo\StoreUnits;
use MPCF\Woo\TrackingEmailExtension;
use MPCF\Woo\WooCustomerEmailLookup;
use MPCF\Woo\WooOrderSource;
use MPCF\Woo\WorkspaceFlags;

/**
 * Wires the object graph by hand and lets each service register its own
 * hooks. There is no container (house convention, see the sibling plugins'
 * `Plugin` classes) — no internal service may instantiate a peer; every
 * dependency is constructor-injected from here.
 *
 * Milestone 0 wired nothing beyond the textdomain and the migration
 * drift-check. Milestone 1 builds the plugin's real service graph.
 * {@see init()} constructs every collaborator shared across contexts
 * exactly once — repositories, the {@see EventDispatcher}, {@see Clock},
 * {@see Settings} and the one {@see WorkflowService} — and hands the same
 * instances to both {@see wire_services()} (intake, the status bridge, the
 * inbound observer, and — only under WP-CLI — the backfill command,
 * always) and {@see wire_admin()} (the Fulfillment menu and its three
 * screens, only when `is_admin()`). A single shared dispatcher is load
 * bearing, not tidiness: {@see StatusBridge} subscribes to it in
 * {@see wire_services()}, so a `WorkflowService` built with any other
 * dispatcher instance would transition fulfillments whose
 * `fulfillment.state_changed` event reaches no subscriber at all — exactly
 * the defect `v0.1.1` fixes (Architecture Plan §IV.2). None of these
 * collaborators is stored as a property (see
 * `CompositionRootTest::test_plugin_declares_only_singleton_bookkeeping_properties()`);
 * they live only as `init()` locals passed down as parameters, so this
 * class still owns no service-holding state of its own.
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
	 *
	 * Builds every collaborator {@see wire_services()} and {@see wire_admin()}
	 * both need exactly once, then hands the same instances to each — the
	 * single-shared-dispatcher fix `v0.1.1` made to what was previously two
	 * independent, disconnected service graphs (Architecture Plan §IV.2).
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

		$fulfillments = new WpdbFulfillmentRepository();
		$items        = new WpdbFulfillmentItemRepository();
		$events       = new WpdbEventRepository();
		$notes        = new WpdbNoteRepository();
		$shipments    = new WpdbShipmentRepository();
		$packages     = new WpdbPackageRepository();
		$dispatcher   = new EventDispatcher();
		$clock        = new SystemClock();
		$settings     = new Settings();
		$definition   = StandardWorkflow::definition();
		$photo_config = PhotoConfig::from_settings( $settings );
		$media_repo   = new WpdbMediaRepository();
		$photo_store  = new ProtectedPhotoStore();

		$photo_service = new PhotoService(
			$media_repo,
			$photo_store,
			new GdImageProcessor( $photo_config->max_edge_px() ),
			$fulfillments,
			$packages,
			$shipments,
			$events,
			$dispatcher,
			$clock,
			$photo_config
		);

		$photo_retention = new PhotoRetentionService(
			$media_repo,
			$photo_store,
			$events,
			$dispatcher,
			$clock,
			static function () use ( $settings ): int {
				return $settings->photos_retention_months();
			}
		);

		$workflow_service = new WorkflowService(
			$fulfillments,
			$events,
			new WorkflowEngine( GuardRegistry::standard() ),
			$dispatcher,
			$clock,
			array( StandardWorkflow::NAME => $definition ),
			new TransitionContextFactory( $items, $shipments, $packages, $settings, $photo_service )
		);

		$this->wire_services( $fulfillments, $items, $events, $shipments, $packages, $dispatcher, $clock, $settings, $definition, $workflow_service, $photo_service, $photo_retention );

		// Architecture Plan §5.4: menu/screens/assets are gated to is_admin()
		// contexts only — a front-end or WP-CLI request never needs them.
		if ( is_admin() ) {
			$this->wire_admin( $fulfillments, $items, $events, $notes, $shipments, $packages, $dispatcher, $clock, $settings, $definition, $workflow_service, $media_repo );
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
	 * Builds every Milestone 1 service that is not admin-only and registers
	 * its platform hooks, from the collaborators {@see init()} already
	 * built once. Runs unconditionally from `init()` (itself gated on the
	 * order platform being active by the main plugin file) — registering
	 * the hooks here, rather than deferring to a later action, is what
	 * makes them present in time for the same request's checkout/order-admin
	 * processing to fire them.
	 *
	 * @param WpdbFulfillmentRepository     $fulfillments Fulfillment persistence, shared with {@see wire_admin()}.
	 * @param WpdbFulfillmentItemRepository $items        Line-item persistence, shared with {@see wire_admin()}.
	 * @param WpdbEventRepository           $events       Audit-log persistence, shared with {@see wire_admin()}.
	 * @param WpdbShipmentRepository        $shipments    Shipment persistence, shared with {@see wire_admin()} via `$workflow_service`'s context factory.
	 * @param WpdbPackageRepository         $packages     Package persistence, shared with {@see wire_admin()} via `$workflow_service`'s context factory.
	 * @param EventDispatcher               $dispatcher   In-process event dispatch — the one instance {@see StatusBridge} subscribes to below and `$workflow_service` dispatches through.
	 * @param SystemClock                   $clock        Source of "now", shared with {@see wire_admin()}.
	 * @param Settings                      $settings     Plugin settings, shared with {@see wire_admin()}.
	 * @param WorkflowDefinition            $definition   The governing workflow, shared with {@see wire_admin()}.
	 * @param WorkflowService               $workflow_service The one {@see WorkflowService}, built in {@see init()} against `$dispatcher` and shared with {@see wire_admin()} — the fix that makes an admin-initiated transition reach `$dispatcher`'s subscribers, including the status bridge subscribed just below.
	 * @param PhotoService                  $photo_service    Package photography orchestrator, shared with the REST surface.
	 * @param PhotoRetentionService         $photo_retention  Bounded retention purge orchestrator.
	 */
	private function wire_services(
		WpdbFulfillmentRepository $fulfillments,
		WpdbFulfillmentItemRepository $items,
		WpdbEventRepository $events,
		WpdbShipmentRepository $shipments,
		WpdbPackageRepository $packages,
		EventDispatcher $dispatcher,
		SystemClock $clock,
		Settings $settings,
		WorkflowDefinition $definition,
		WorkflowService $workflow_service,
		PhotoService $photo_service,
		PhotoRetentionService $photo_retention
	): void {
		$orders = new WooOrderSource();

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
		( new PhotoRetentionScheduler( $photo_retention ) )->register();

		$analytics_engine  = new AnalyticsEngine( new WpdbAnalyticsEventSource(), new WpdbAnalyticsDailyRepository() );
		$analytics_service = new AnalyticsService(
			$analytics_engine,
			$clock,
			$definition,
			new WpdbAnalyticsDiagnosticsReader()
		);
		$analytics_csv     = new AnalyticsCsvExporter();
		( new AnalyticsRollupScheduler( $analytics_service ) )->register();

		$shipping_service = new ShippingService(
			$fulfillments,
			$items,
			$shipments,
			$packages,
			new WpdbPackageItemRepository(),
			$events,
			$dispatcher,
			$clock
		);

		// Architecture Plan §IV.5.8 step 11: a fulfillment reaching `shipped`
		// ships every shipment still `pending` on it too. Same shared
		// `$dispatcher` as the status bridge below, so this reacts to a
		// transition dispatched through any path, admin-initiated included.
		$dispatcher->subscribe( 'fulfillment.state_changed', new ShipmentAutoShipSubscriber( $shipping_service ) );

		// The outbound bridge is just another subscriber on the same event
		// bus intake uses (invariant I4's single-writer rule already
		// guarantees WorkflowService is the only thing that can ever
		// dispatch a fulfillment.state_changed event for it to react to).
		// `$dispatcher` is the same instance `$workflow_service` was built
		// with — shared from `init()` — so this subscription now reacts to
		// every transition dispatched through it, admin-initiated included.
		$dispatcher->subscribe( 'fulfillment.state_changed', new StatusBridge( $fulfillments, $settings ) );

		$carriers             = new BundledCarrierRegistry();
		$notification_config  = new NotificationConfigurationService( $settings, $carriers );
		$notification_service = new NotificationService(
			$notification_config,
			new NotificationFactory( $notification_config, $carriers, new WooCustomerEmailLookup() ),
			new EmailChannel(),
			$fulfillments,
			$shipments,
			$packages,
			$events,
			$dispatcher,
			$clock
		);
		$dispatcher->subscribe( 'shipment.shipped', new NotificationDispatcher( $notification_service ) );
		( new TrackingEmailExtension( $notification_config, $fulfillments, $shipments, $packages, $carriers ) )->register();

		( new RefundObserver( $fulfillments, $items, $orders, $workflow_service, $settings ) )->register();

		// M10: shared CheckerRegistry powers doctor + Site Health; repairs audit via MaintenanceAuditor.
		$checker_registry = DefaultCheckerRegistryFactory::create();
		$doctor_service   = new DoctorService( $checker_registry );
		$validation       = new ValidationService( $checker_registry );
		$maintenance      = new MaintenanceAuditor( $events, $dispatcher, $clock );
		$audit_verifier   = new AuditChainVerifier( $events );
		$privacy_eraser   = new PrivacyEraser();
		( new SiteHealthRegistrar( $checker_registry ) )->register();
		( new PrivacyRegistrar( new PrivacyExporter(), $privacy_eraser ) )->register();
		( new PrivacyHooks( $privacy_eraser ) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new BackfillCommand( $orders, $intake ) )->register();
			( new AnalyticsCommand( $analytics_service ) )->register();
			( new DoctorCommand( $doctor_service ) )->register();
			( new ValidateCommand( $validation ) )->register();
			( new RepairCommand(
				new ScheduleRepairService( $maintenance ),
				new StorageDirsRepairService( $maintenance ),
				new SchemaRepairService( $maintenance ),
				new CapabilitiesRepairService( $maintenance )
			) )->register();
			( new AuditCommand( $audit_verifier ) )->register();
		}

		// Architecture Plan §IV.9: mpcf/v1 is unconditional, not gated on
		// is_admin() — a REST client is never an admin request. It reuses
		// the same $workflow_service instance the admin screens do, so a
		// transition submitted either way produces identical outcomes
		// (§IV.15 criterion 2).
		$notes_repository = new WpdbNoteRepository();
		$detail_service   = new FulfillmentDetailService( $fulfillments, $items, $events, $notes_repository, new WpdbMediaRepository(), $shipments, $packages );
		$packing_service  = new PackingService( $fulfillments, $items, $events, $dispatcher, $clock );
		$scan_service     = new ScanService(
			$fulfillments,
			$items,
			$packing_service,
			new ScanResolver(),
			$packages,
			$shipments,
			new TransientScanCorrectionStore(),
			$events,
			$dispatcher,
			$clock
		);

		$assignments_svc = new AssignmentService( $fulfillments, $events, $dispatcher, $clock );
		$wave_repo       = new WpdbWaveRepository();
		$wave_service    = new WaveService(
			$wave_repo,
			$fulfillments,
			$items,
			$assignments_svc,
			$workflow_service,
			$events,
			$dispatcher,
			$clock,
			$settings
		);
		$wave_scan       = new WaveScanService(
			$wave_repo,
			$fulfillments,
			$items,
			$packing_service,
			$workflow_service,
			$wave_service,
			new ScanResolver(),
			new TransientScanCorrectionStore(),
			$events,
			$dispatcher,
			$clock
		);

		$document_repo    = new WpdbDocumentRepository();
		$document_store   = new ProtectedDocumentStore();
		$document_service = new DocumentService(
			$fulfillments,
			$items,
			$orders,
			$shipping_service,
			new HtmlRenderer( new TemplateRegistry() ),
			$document_repo,
			$events,
			$dispatcher,
			$clock,
			(string) get_bloginfo( 'name' ),
			null,
			$settings,
			$document_store
		);
		$document_history = new DocumentHistoryService(
			$document_repo,
			$document_store,
			$events,
			$dispatcher,
			$clock,
			$document_service
		);

		( new RestApi(
			array(
				new FulfillmentsController(
					new QueueService( $fulfillments, new WpdbSearchQuery() ),
					$detail_service,
					$workflow_service,
					$definition
				),
				new ItemsController(
					$packing_service,
					$workflow_service
				),
				new NotesController( new NoteService( $notes_repository, $clock ) ),
				new AssignmentController( $assignments_svc ),
				new ShipmentsController( $shipping_service, $workflow_service, $detail_service ),
				new PackagesController( $shipping_service, $workflow_service, $detail_service ),
				new CarriersController( $carriers ),
				new NotificationsController( $notification_service ),
				new DocumentsController( $document_service, $document_history, $workflow_service, $detail_service ),
				new PhotosController( $photo_service, $workflow_service, $detail_service ),
				new ScanController( $scan_service, $workflow_service ),
				new WavesController( $wave_service, $wave_scan, $document_service ),
				new AnalyticsController( $analytics_service, $analytics_csv ),
			)
		) )->register();
	}

	/**
	 * Builds Milestone 1's admin screens (D15-D18: Queue, Fulfillment
	 * Detail, Dashboard) and registers the Fulfillment menu, from the
	 * collaborators {@see init()} already built once — including
	 * `$workflow_service`, the same instance {@see wire_services()} wired
	 * against the shared {@see EventDispatcher}, so a transition submitted
	 * from the Queue or Fulfillment Detail dispatches to the same
	 * subscribers (the status bridge included) as one submitted any other
	 * way (Architecture Plan §IV.2 — the `v0.1.1` fix). Fulfillment Detail
	 * is registered as a real submenu page (so its capability check and
	 * URL both work) and then immediately removed from the visible menu —
	 * it is reached from the Queue/Dashboard, never a standalone nav
	 * destination (Architecture Plan §9.3).
	 *
	 * @param WpdbFulfillmentRepository     $fulfillments     Fulfillment persistence, shared with {@see wire_services()}.
	 * @param WpdbFulfillmentItemRepository $items            Line-item persistence, shared with {@see wire_services()}.
	 * @param WpdbEventRepository           $events           Audit-log persistence, shared with {@see wire_services()}.
	 * @param WpdbNoteRepository            $notes            Note persistence (admin-only in Milestone 1).
	 * @param WpdbShipmentRepository        $shipments        Shipment persistence, shared with {@see wire_services()}.
	 * @param WpdbPackageRepository         $packages         Package persistence, shared with {@see wire_services()}.
	 * @param EventDispatcher               $dispatcher       In-process event dispatch, shared with {@see wire_services()}.
	 * @param SystemClock                   $clock            Source of "now", shared with {@see wire_services()}.
	 * @param Settings                      $settings         Plugin settings, shared with {@see wire_services()}.
	 * @param WorkflowDefinition            $definition       The governing workflow, shared with {@see wire_services()} via `$workflow_service`.
	 * @param WorkflowService               $workflow_service The one {@see WorkflowService}, shared with {@see wire_services()}.
	 * @param WpdbMediaRepository           $media            Package photography persistence for the CS gallery.
	 */
	private function wire_admin(
		WpdbFulfillmentRepository $fulfillments,
		WpdbFulfillmentItemRepository $items,
		WpdbEventRepository $events,
		WpdbNoteRepository $notes,
		WpdbShipmentRepository $shipments,
		WpdbPackageRepository $packages,
		EventDispatcher $dispatcher,
		SystemClock $clock,
		Settings $settings,
		WorkflowDefinition $definition,
		WorkflowService $workflow_service,
		WpdbMediaRepository $media
	): void {
		$renderer = new ComponentRenderer();
		$shell    = new AdminPageShell( new SectionNavigation() );

		$queue_service       = new QueueService( $fulfillments, new WpdbSearchQuery() );
		$detail_service      = new FulfillmentDetailService( $fulfillments, $items, $events, $notes, $media, $shipments, $packages );
		$note_service        = new NoteService( $notes, $clock );
		$assignments         = new AssignmentService( $fulfillments, $events, $dispatcher, $clock );
		$dashboard           = new DashboardService( $fulfillments, $events, $clock );
		$shipping_service    = new ShippingService( $fulfillments, $items, $shipments, $packages, new WpdbPackageItemRepository(), $events, $dispatcher, $clock );
		$carriers            = new BundledCarrierRegistry();
		$notification_config = new NotificationConfigurationService( $settings, $carriers );
		$document_repo       = new WpdbDocumentRepository();
		$document_store      = new ProtectedDocumentStore();
		$document_service    = new DocumentService(
			$fulfillments,
			$items,
			new WooOrderSource(),
			$shipping_service,
			new HtmlRenderer( new TemplateRegistry() ),
			$document_repo,
			$events,
			$dispatcher,
			$clock,
			(string) get_bloginfo( 'name' ),
			null,
			$settings,
			$document_store
		);
		$document_history    = new DocumentHistoryService(
			$document_repo,
			$document_store,
			$events,
			$dispatcher,
			$clock,
			$document_service
		);

		$dashboard_page = new DashboardPage( $shell, $renderer, $dashboard, $definition );
		$queue_page     = new QueuePage( $shell, $renderer, $queue_service, $detail_service, $assignments, $workflow_service, $definition, $document_history );
		$orders_page    = new OrdersPage(
			$shell,
			$renderer,
			new OrderOverviewService( new WooOrderSource(), $fulfillments, new WpdbSearchQuery() )
		);
		$settings_page  = new SettingsPage( $shell, $renderer, $settings, $carriers, $notification_config );
		$documents_page = new DocumentsPage( $shell, $document_history );
		$detail_page    = new FulfillmentDetailPage( $shell, $renderer, $detail_service, $note_service, $workflow_service, $definition );
		$workspace_page = new WorkspacePage(
			$shell,
			$renderer,
			$detail_service,
			$workflow_service,
			$shipping_service,
			$note_service,
			$carriers,
			$assignments,
			new WooOrderSource(),
			$definition,
			new StoreUnits(),
			$document_repo,
			$settings
		);
		$wave_page      = new WavePage( $shell, $renderer );
		$analytics_page = new AnalyticsPage(
			$shell,
			$renderer,
			new AnalyticsService(
				new AnalyticsEngine( new WpdbAnalyticsEventSource(), new WpdbAnalyticsDailyRepository() ),
				$clock,
				$definition,
				new WpdbAnalyticsDiagnosticsReader()
			),
			new AnalyticsCsvExporter()
		);

		( new Menu(
			DashboardPage::SLUG,
			__( 'Fulfillment', 'mp-commerce-fulfillment' ),
			'dashicons-archive',
			Capabilities::VIEW_QUEUE,
			array( $dashboard_page, $queue_page, $orders_page, $documents_page, $analytics_page, $settings_page )
		) )->register();

		$hidden_pages = array( $detail_page, $workspace_page, $wave_page );

		add_action(
			'admin_menu',
			static function () use ( $hidden_pages ) {
				foreach ( $hidden_pages as $hidden_page ) {
					add_submenu_page(
						DashboardPage::SLUG,
						$hidden_page->title(),
						$hidden_page->menu_title(),
						$hidden_page->capability(),
						$hidden_page->slug(),
						array( $hidden_page, 'render' )
					);
				}
			},
			20
		);

		// Hides these pages from the visible nav via CSS only — never
		// `remove_submenu_page()`. That function deletes the entry from
		// the `$submenu` global, which is also the ONLY place
		// `get_admin_page_parent()` can resolve this page's parent from;
		// once it can't, `user_can_access_admin_page()` computes the
		// wrong lookup key and denies every user, including
		// administrators, a 403 on direct URL access — breaking
		// "reachable by URL and capability-checked, never a visible nav
		// item" (§9.3/§IV.5.1) entirely, not just the "hidden" half of
		// it. A real regression from Milestone 1 onward, caught by the
		// Playwright suite's first real HTTP request against this page,
		// not by any PHPUnit test — those all render these pages via a
		// direct method call, never through wp-admin's own routing (F22).
		add_action(
			'admin_head',
			static function () use ( $hidden_pages ) {
				echo '<style>';

				foreach ( $hidden_pages as $hidden_page ) {
					printf( '.wp-submenu a[href*="page=%s"]{display:none}', esc_attr( $hidden_page->slug() ) );
				}

				echo '</style>';
			}
		);

		( new Assets() )->register();
		( new OperatorMode( $settings ) )->register();
		( new WorkspaceFlags( $fulfillments, $definition ) )->register();
	}
}
