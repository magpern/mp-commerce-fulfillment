<?php
/**
 * Milestone 1 acceptance criterion 3 / Architecture Plan §III.2.2: proves
 * every real Queue/Dashboard query shape stays indexed at 10,000 rows.
 * Re-run for Milestone 2 (F23, §IV.10/§IV.15) with an M2-shaped event
 * distribution (14 event types, not M1's one) and two new shapes —
 * workspace load and a tracking-number lookup — added alongside the
 * original M1 shapes rather than replacing them.
 *
 * Not part of `composer test:integration` or CI — seeding 10,000
 * fulfillments (plus their items, audit events, shipments and packages) on
 * every run would slow the whole suite down for no benefit once this proof
 * has run once per schema change. Run explicitly, against the same Docker
 * test environment as the rest of the integration suite:
 *
 *   docker run --rm --network mpcf-test-net -v "$PWD":/app -w /app \
 *     -e WP_DB_HOST=mpcf-test-db mpcf-test-runner:latest \
 *     bash -c "bash tests/bin/install-wp.sh && vendor/bin/phpunit -c phpunit-performance.xml.dist"
 *
 * Findings are recorded in `docs/QUEUE_PERFORMANCE_VALIDATION.md` — rerun
 * this file and update that document whenever the schema, an index, or one
 * of these query shapes changes.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Performance;

use MPCF\Application\DashboardService;
use MPCF\Application\EventDispatcher;
use MPCF\Application\QueueService;
use MPCF\Application\ShippingService;
use MPCF\Domain\FulfillmentQuery;
use MPCF\Domain\Workflow\StandardWorkflow;
use MPCF\Infrastructure\Database\Schema;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentItemRepository;
use MPCF\Infrastructure\Database\WpdbFulfillmentRepository;
use MPCF\Infrastructure\Database\WpdbNoteRepository;
use MPCF\Infrastructure\Database\WpdbPackageItemRepository;
use MPCF\Infrastructure\Database\WpdbPackageRepository;
use MPCF\Infrastructure\Database\WpdbSearchQuery;
use MPCF\Infrastructure\Database\WpdbShipmentRepository;
use MPCF\Infrastructure\SystemClock;
use MPCF\Plugin;
use WP_UnitTestCase;

/**
 * Seeds once for the whole class (outside any per-test transaction — see
 * {@see setUpBeforeClass()}) and only ever reads afterward, so every test
 * method shares the same 10,000-row dataset.
 */
final class QueuePerformanceProofTest extends WP_UnitTestCase {

	private const TOTAL_FULFILLMENTS = 10000;
	private const ITERATIONS         = 20;

	/**
	 * Realistic warehouse state distribution summing to
	 * {@see TOTAL_FULFILLMENTS}: most orders eventually complete, a modest
	 * slice sits in-flight, a small slice is stuck in an exception state.
	 * Order matches {@see StandardWorkflow}'s own state list so partial
	 * paths (below) can be sliced from it positionally.
	 *
	 * @var array<string, int>
	 */
	private const STATE_COUNTS = array(
		'completed'   => 7000,
		'cancelled'   => 500,
		'queued'      => 700,
		'picking'     => 500,
		'picked'      => 400,
		'packing'     => 350,
		'packed'      => 300,
		'shipped'     => 150,
		'delivered'   => 60,
		'problem'     => 20,
		'waiting'     => 15,
		'backordered' => 5,
	);

	/**
	 * The linear path a fulfillment in each state took to get there, for
	 * synthetic audit-event generation (shortcut edges are not modeled —
	 * volume/shape realism, not exact path fidelity, is what this dataset
	 * needs).
	 *
	 * @var array<string, list<string>>
	 */
	private const STATE_PATH = array(
		'queued'      => array( 'queued' ),
		'picking'     => array( 'queued', 'picking' ),
		'picked'      => array( 'queued', 'picking', 'picked' ),
		'packing'     => array( 'queued', 'picking', 'picked', 'packing' ),
		'packed'      => array( 'queued', 'picking', 'picked', 'packing', 'packed' ),
		'shipped'     => array( 'queued', 'picking', 'picked', 'packing', 'packed', 'shipped' ),
		'delivered'   => array( 'queued', 'picking', 'picked', 'packing', 'packed', 'shipped', 'delivered' ),
		'completed'   => array( 'queued', 'picking', 'picked', 'packing', 'packed', 'shipped', 'delivered', 'completed' ),
		'problem'     => array( 'queued', 'picking', 'problem' ),
		'waiting'     => array( 'queued', 'picking', 'picked', 'waiting' ),
		'backordered' => array( 'queued', 'backordered' ),
		'cancelled'   => array( 'queued', 'cancelled' ),
	);

	private const OPEN_STATES      = array( 'queued', 'picking', 'picked', 'packing', 'packed', 'shipped', 'delivered', 'problem', 'waiting', 'backordered' );
	private const EXCEPTION_STATES = array( 'problem', 'waiting', 'backordered' );

	/**
	 * Synthetic customer names — clearly fake, never real PII (D21's own
	 * "no real PII" requirement) — repeated across fulfillments so a
	 * customer-name-prefix search matches a realistic multi-row bucket
	 * instead of exactly one row.
	 *
	 * @var list<string>
	 */
	private const NAME_POOL = array(
		'Alex Warehouse',
		'Blake Testcustomer',
		'Casey Sampledata',
		'Drew Fixtureperson',
		'Ellis Placeholder',
		'Frankie Exampleuser',
		'Gray Syntheticbuyer',
		'Harper Mockclient',
		'Indigo Fakename',
		'Jordan Dummyshopper',
		'Kai Stubcustomer',
		'Lane Testaccount',
	);

	/**
	 * Whether {@see setUpBeforeClass()} has already seeded the dataset in
	 * this process.
	 *
	 * @var bool
	 */
	private static bool $seeded = false;

	/**
	 * A fulfillment id that reached `packed` — has a shipment, a package,
	 * and a `mpcf_shipments.tracking_number` — for the workspace-load and
	 * tracking-search shapes below. Set by {@see seed_dataset()}.
	 *
	 * @var int
	 */
	private static int $packed_fulfillment_id = 0;

	/**
	 * `TRACK-{order_id}` for {@see $packed_fulfillment_id}'s shipment.
	 *
	 * @var string
	 */
	private static string $known_tracking_number = '';

	/**
	 * Seeds the 10,000-row dataset exactly once for the whole class, via
	 * direct bulk SQL — outside any per-test transaction, since
	 * `WP_UnitTestCase`'s transaction wrapping only starts inside instance
	 * `setUp()`/`parent::setUp()`, which this class never calls. The data
	 * is committed and stays visible to every test method in this process.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( self::$seeded ) {
			return;
		}

		Plugin::activate();
		self::seed_dataset();
		self::$seeded = true;
	}

	/**
	 * Deliberately does not call `parent::setUp()` — this class needs no
	 * per-test transaction (every test here only reads the class-level
	 * seeded dataset) and a per-test transaction would not roll back the
	 * bulk inserts anyway, since they ran in `setUpBeforeClass()`, before
	 * any transaction existed.
	 */
	protected function setUp(): void {
		self::assertTrue( self::$seeded, 'Dataset must be seeded by setUpBeforeClass().' );
	}

	public static function tearDownAfterClass(): void {
		global $wpdb;

		foreach ( Schema::all_tables() as $table ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- $table is Schema-built, never user input.
		}

		self::$seeded = false;

		parent::tearDownAfterClass();
	}

	public function test_default_open_queue_is_indexed(): void {
		$queue = $this->queue_service();
		$query = new FulfillmentQuery( self::OPEN_STATES, null, null, null, 'created_at', 'DESC', 1, 20 );

		$result = $this->measure( 'default open Queue (state IN open, ORDER BY created_at DESC LIMIT 20)', static fn() => $queue->list( $query ) );

		self::assertGreaterThan( 0, $result['result']->total(), 'Sanity: the open queue must not be empty.' );
	}

	public function test_single_state_filter_is_indexed(): void {
		$queue = $this->queue_service();
		$query = new FulfillmentQuery( array( 'picking' ), null, null, null, 'created_at', 'DESC', 1, 20 );

		$this->measure( 'state filter (state = picking)', static fn() => $queue->list( $query ) );
	}

	public function test_assignee_filter_is_indexed(): void {
		$queue = $this->queue_service();
		$query = new FulfillmentQuery( self::OPEN_STATES, 501, null, null, 'created_at', 'DESC', 1, 20 );

		$this->measure( 'assignee filter (assignee_type/id + state)', static fn() => $queue->list( $query ) );
	}

	public function test_unassigned_filter_is_indexed(): void {
		$queue = $this->queue_service();
		$query = new FulfillmentQuery( self::OPEN_STATES, FulfillmentQuery::SENTINEL_UNASSIGNED, null, null, 'created_at', 'DESC', 1, 20 );

		$this->measure( 'unassigned filter (assignee_id IS NULL + state)', static fn() => $queue->list( $query ) );
	}

	public function test_age_filter_is_indexed(): void {
		$queue = $this->queue_service();
		$query = new FulfillmentQuery( self::OPEN_STATES, null, null, DAY_IN_SECONDS, 'created_at', 'DESC', 1, 20 );

		$this->measure( 'age filter (state_entered_at <= threshold + state)', static fn() => $queue->list( $query ) );
	}

	public function test_numeric_search_is_indexed(): void {
		$this->assert_search_lookup_is_indexed( '605000', 'numeric search lookup (id/order_id)' );

		$queue = $this->queue_service();

		// order_id 605000 is inside the seeded range (600001-610000) and
		// therefore actually matches a row - a search term outside that
		// range would take the "no candidate ids" short-circuit
		// (FulfillmentRepository::where_clause()'s `1 = 0` branch) instead
		// of exercising the indexed id/order_id lookup this test exists to prove.
		$result = $this->measure( 'numeric search, end to end (lookup + listing)', static fn() => $queue->list( new FulfillmentQuery( array(), null, null, null, 'created_at', 'DESC', 1, 20 ), '605000' ) );

		self::assertGreaterThan( 0, $result['result']->total(), 'Sanity: the search term must actually match a seeded row.' );
	}

	public function test_sku_prefix_search_is_indexed(): void {
		$this->assert_search_lookup_is_indexed( 'SKU-1', 'SKU-prefix search lookup (sku_snapshot LIKE prefix%)' );

		$queue = $this->queue_service();

		$result = $this->measure( 'SKU-prefix search, end to end (lookup + listing)', static fn() => $queue->list( new FulfillmentQuery( array(), null, null, null, 'created_at', 'DESC', 1, 20 ), 'SKU-1' ) );

		self::assertGreaterThan( 0, $result['result']->total(), 'Sanity: the search term must actually match a seeded row.' );
	}

	public function test_customer_name_prefix_search_is_indexed(): void {
		$this->assert_search_lookup_is_indexed( 'Alex', 'customer-name-prefix search lookup (customer_name_snapshot LIKE prefix%)' );

		$queue = $this->queue_service();

		$result = $this->measure( 'customer-name-prefix search, end to end (lookup + listing)', static fn() => $queue->list( new FulfillmentQuery( array(), null, null, null, 'created_at', 'DESC', 1, 20 ), 'Alex' ) );

		self::assertGreaterThan( 0, $result['result']->total(), 'Sanity: the search term must actually match a seeded row.' );
	}

	public function test_dashboard_exception_list_is_indexed(): void {
		$dashboard = $this->dashboard_service();

		$this->measure( 'Dashboard needs-attention (exception states, ORDER BY state_entered_at)', static fn() => $dashboard->needs_attention( StandardWorkflow::definition() ) );
	}

	public function test_dashboard_oldest_open_list_is_indexed(): void {
		$dashboard = $this->dashboard_service();

		$this->measure( 'Dashboard oldest-open (open states, ORDER BY created_at)', static fn() => $dashboard->oldest_open( StandardWorkflow::definition() ) );
	}

	public function test_dashboard_unassigned_list_is_indexed(): void {
		$dashboard = $this->dashboard_service();

		$this->measure( 'Dashboard unassigned (open states + assignee_id IS NULL)', static fn() => $dashboard->unassigned( StandardWorkflow::definition() ) );
	}

	public function test_dashboard_stat_counts_are_indexed(): void {
		$dashboard = $this->dashboard_service();

		$this->measure( 'Dashboard open_count (COUNT WHERE state IN open)', static fn() => $dashboard->open_count( StandardWorkflow::definition() ) );
		$this->measure( 'Dashboard exception_count (COUNT WHERE state IN exception)', static fn() => $dashboard->exception_count( StandardWorkflow::definition() ) );
		$this->measure( 'Dashboard packed_today (event_type + created_at range)', static fn() => $dashboard->packed_today() );
		$this->measure( 'Dashboard shipped_today (event_type + created_at range)', static fn() => $dashboard->shipped_today() );
	}

	/**
	 * F23 (Architecture Plan §IV.10): the workspace's server-rendered
	 * initial load — one fulfillment plus its items, last-5 timeline,
	 * shipments/packages and notes — each read checked independently (this
	 * is several distinct queries, not one `measure()`-able statement), and
	 * the whole bundle timed end to end against §IV.15's own 300ms budget
	 * for this shape (not the general 200ms default).
	 */
	public function test_workspace_load_is_indexed(): void {
		self::assertGreaterThan( 0, self::$packed_fulfillment_id, 'Sanity: seeding must have recorded a packed fulfillment id.' );

		$id           = self::$packed_fulfillment_id;
		$fulfillments = new WpdbFulfillmentRepository();
		$items        = new WpdbFulfillmentItemRepository();
		$events       = new WpdbEventRepository();
		$notes        = new WpdbNoteRepository();
		$shipping     = new ShippingService(
			$fulfillments,
			$items,
			new WpdbShipmentRepository(),
			new WpdbPackageRepository(),
			new WpdbPackageItemRepository(),
			$events,
			new EventDispatcher(),
			new SystemClock()
		);

		$fulfillments->find( $id );
		$this->assert_last_query_is_indexed( 'workspace load: fulfillment lookup (PRIMARY)' );

		$items->find_for_fulfillment( $id );
		$this->assert_last_query_is_indexed( 'workspace load: items (fulfillment_id)' );

		$events->recent_for_fulfillment( $id, 5 );
		$this->assert_last_query_is_indexed( 'workspace load: last-5 timeline (fulfillment_id, ORDER BY id DESC LIMIT 5)' );

		$shipping->list_for_fulfillment( $id );
		$this->assert_last_query_is_indexed( 'workspace load: packages (shipment_id)' );

		$notes->find_for_fulfillment( $id );
		$this->assert_last_query_is_indexed( 'workspace load: notes (fulfillment_id)' );

		$this->measure(
			'workspace load, end to end (fulfillment + items + timeline(5) + shipments/packages + notes)',
			static function () use ( $fulfillments, $items, $events, $shipping, $notes, $id ) {
				$fulfillments->find( $id );
				$items->find_for_fulfillment( $id );
				$events->recent_for_fulfillment( $id, 5 );
				$shipping->list_for_fulfillment( $id );
				$notes->find_for_fulfillment( $id );
			},
			300.0
		);
	}

	/**
	 * F23 (Architecture Plan §IV.6/D22): "`SearchQuery` must resolve a
	 * scanned tracking number... without an unindexed scan" is a schema
	 * readiness property — `mpcf_shipments.tracking_number` already carries
	 * its own `KEY` ({@see Schema::shipments_ddl()}). Wiring the Queue's
	 * user-facing search box to actually classify tracking-shaped terms
	 * ({@see \MPCF\Domain\SearchTermClassifier}) is not part of the M2 UI
	 * this milestone shipped (the workspace resolves a fulfillment's own
	 * shipment directly by fulfillment id, never by searching a tracking
	 * number) — recorded here as a scope decision, not silently left
	 * unmeasured. This proves the property the schema promises; it is not,
	 * itself, evidence that the Queue's search box supports this today.
	 */
	public function test_tracking_number_lookup_is_indexed(): void {
		global $wpdb;

		self::assertNotSame( '', self::$known_tracking_number, 'Sanity: seeding must have recorded a known tracking number.' );

		$table = Schema::table( Schema::SHIPMENTS );

		$this->measure(
			'tracking-number lookup (mpcf_shipments.tracking_number, exact match)',
			function () use ( $wpdb, $table ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is Schema-built, never user input.
				return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE tracking_number = %s", self::$known_tracking_number ), ARRAY_A );
			}
		);
	}

	// -----------------------------------------------------------------
	// Harness
	// -----------------------------------------------------------------

	private function queue_service(): QueueService {
		return new QueueService( new WpdbFulfillmentRepository(), new WpdbSearchQuery() );
	}

	private function dashboard_service(): DashboardService {
		return new DashboardService( new WpdbFulfillmentRepository(), new WpdbEventRepository(), new SystemClock() );
	}

	/**
	 * `QueueService::list()` issues two queries for a search term: the
	 * {@see WpdbSearchQuery} lookup, then the listing query. `measure()`
	 * only ever sees `$wpdb->last_query` (the listing query) — this checks
	 * the lookup query itself, the one actually running the `LIKE
	 * 'prefix%'`/id-or-order_id scan, independently and first.
	 *
	 * @param string $term  Search term to resolve.
	 * @param string $label Human-readable name for this lookup shape.
	 */
	private function assert_search_lookup_is_indexed( string $term, string $label ): void {
		( new WpdbSearchQuery() )->search( $term );

		$this->assert_last_query_is_indexed( $label );
	}

	/**
	 * Checks `EXPLAIN` on whatever `$wpdb->last_query` currently holds —
	 * the shared assertion {@see assert_search_lookup_is_indexed()} and the
	 * workspace-load shape's per-read checks both need.
	 *
	 * @param string $label Human-readable name for this query shape.
	 */
	private function assert_last_query_is_indexed( string $label ): void {
		global $wpdb;

		$explain = $wpdb->get_results( 'EXPLAIN ' . $wpdb->last_query, ARRAY_A ) ?? array();

		foreach ( $explain as $row ) {
			self::assertNotSame(
				'ALL',
				$row['type'] ?? null,
				"{$label}: EXPLAIN shows a full table scan on {$row['table']} — {$wpdb->last_query}"
			);
		}

		fwrite( STDERR, sprintf( "\n[perf] %-70s key=%s type=%s rows=%s\n", $label, $explain[0]['key'] ?? 'NULL', $explain[0]['type'] ?? '?', $explain[0]['rows'] ?? '?' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- STDERR diagnostic output for a manual performance run, not a filesystem write.
	}

	/**
	 * Runs `$call` {@see ITERATIONS} times, capturing wall-clock timing
	 * (first run = cold, rest = warm) and an `EXPLAIN` of the last query
	 * `$wpdb` actually issued — the real query the production code ran,
	 * not a hand-copied approximation of it. Prints a one-line report to
	 * PHPUnit's output (captured into `docs/QUEUE_PERFORMANCE_VALIDATION.md`
	 * by hand after a run) and fails the test if `EXPLAIN` shows a full
	 * table scan.
	 *
	 * @param string   $label        Human-readable name for this query shape.
	 * @param callable $call         Zero-arg call to measure.
	 * @param float    $threshold_ms p95 budget in milliseconds — the reference container's 200ms default for most shapes, or a shape-specific figure (Architecture Plan §IV.15's workspace-load budget is 300ms, not 200ms).
	 * @return array{result: mixed, timings: list<float>}
	 */
	private function measure( string $label, callable $call, float $threshold_ms = 200.0 ): array {
		global $wpdb;

		$timings = array();
		$result  = null;

		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$start     = microtime( true );
			$result    = $call();
			$timings[] = ( microtime( true ) - $start ) * 1000.0;
		}

		$explain = $wpdb->get_results( 'EXPLAIN ' . $wpdb->last_query, ARRAY_A ) ?? array();

		foreach ( $explain as $row ) {
			self::assertNotSame(
				'ALL',
				$row['type'] ?? null,
				"{$label}: EXPLAIN shows a full table scan on {$row['table']} — {$wpdb->last_query}"
			);
		}

		sort( $timings );
		$cold = $timings[0];
		$p50  = $timings[ (int) floor( self::ITERATIONS * 0.5 ) ];
		$p95  = $timings[ (int) floor( self::ITERATIONS * 0.95 ) - 1 ];

		fwrite( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- STDERR diagnostic output for a manual performance run, not a filesystem write.
			STDERR,
			sprintf(
				"\n[perf] %-70s cold=%.2fms p50=%.2fms p95=%.2fms rows=%s key=%s type=%s\n",
				$label,
				$cold,
				$p50,
				$p95,
				$explain[0]['rows'] ?? '?',
				$explain[0]['key'] ?? 'NULL',
				$explain[0]['type'] ?? '?'
			)
		);

		self::assertLessThan( $threshold_ms, $p95, "{$label}: p95 of {$p95}ms exceeds the {$threshold_ms}ms reference-container target." );

		return array(
			'result'  => $result,
			'timings' => $timings,
		);
	}

	/**
	 * Bulk-inserts 10,000 fulfillments (plus items and audit events) via
	 * direct SQL, chunked, for speed — going through
	 * `Application\IntakeService`/`WorkflowService` for 10k+ rows would
	 * take far longer than the query proof itself needs to.
	 */
	private static function seed_dataset(): void {
		global $wpdb;

		$fulfillments_table = Schema::table( Schema::FULFILLMENTS );
		$items_table        = Schema::table( Schema::FULFILLMENT_ITEMS );
		$events_table       = Schema::table( Schema::EVENTS );

		$order_id      = 600000;
		$rows          = array();
		$item_rows     = array();
		$event_rows    = array();
		$shipment_rows = array();
		$package_rows  = array();
		$name_index    = 0;

		foreach ( self::STATE_COUNTS as $state => $count ) {
			$is_open = in_array( $state, self::OPEN_STATES, true );

			for ( $i = 0; $i < $count; $i++ ) {
				++$order_id;

				$age_days = $is_open
					? ( in_array( $state, self::EXCEPTION_STATES, true ) ? wp_rand( 1, 20 ) : wp_rand( 0, 14 ) )
					: wp_rand( 3, 120 );

				$created_at       = gmdate( 'Y-m-d H:i:s', time() - ( $age_days * DAY_IN_SECONDS ) - wp_rand( 0, 3600 ) );
				$state_entered_at = gmdate( 'Y-m-d H:i:s', min( time(), strtotime( $created_at ) + wp_rand( 0, 2 * DAY_IN_SECONDS ) ) );
				$completed_at     = in_array( $state, array( 'completed', 'cancelled' ), true ) ? $state_entered_at : null;

				$assignee_id = null;
				if ( ! $is_open || wp_rand( 0, 99 ) >= 35 ) {
					$assignee_id = wp_rand( 501, 520 );
				}

				$item_count     = wp_rand( 1, 5 );
				$name           = self::NAME_POOL[ $name_index % count( self::NAME_POOL ) ];
				$path_so_far    = self::STATE_PATH[ $state ];
				$previous_state = count( $path_so_far ) > 1 ? $path_so_far[ count( $path_so_far ) - 2 ] : null;
				++$name_index;

				$rows[] = array(
					$order_id,
					'woocommerce',
					1,
					'standard',
					$state,
					$previous_state,
					null,
					in_array( $state, self::EXCEPTION_STATES, true ) ? 'Seeded exception for performance validation' : null,
					0,
					null === $assignee_id ? null : 'user',
					$assignee_id,
					1,
					'#' . $order_id,
					$name,
					$item_count,
					$created_at,
					$state_entered_at,
					$completed_at,
				);

				$fulfillment_row_index = count( $rows ) - 1;

				for ( $item_i = 0; $item_i < $item_count; $item_i++ ) {
					$sku         = 'SKU-' . wp_rand( 1000, 9999 );
					$item_rows[] = array( $fulfillment_row_index, $order_id * 10 + $item_i, 900 + $item_i, null, $sku, 'Widget ' . $sku, 1, 0, 0 );
				}

				$path = self::STATE_PATH[ $state ];
				$span = max( 1, strtotime( $state_entered_at ) - strtotime( $created_at ) );

				foreach ( $path as $step_index => $step_state ) {
					$from       = 0 === $step_index ? null : $path[ $step_index - 1 ];
					$is_last    = ( count( $path ) - 1 ) === $step_index;
					$event_time = $is_last
						? $state_entered_at
						: gmdate( 'Y-m-d H:i:s', (int) strtotime( $created_at ) + (int) ( $span * ( $step_index / count( $path ) ) ) );

					$event_rows[] = array(
						$fulfillment_row_index,
						'fulfillment.state_changed',
						'user',
						$assignee_id ?? 1,
						'Seed',
						wp_json_encode(
							array(
								'from' => $from,
								'to'   => $step_state,
							)
						),
						null,
						hash( 'sha256', $order_id . ':' . $step_state ),
						$event_time,
					);
				}

				// M2-shaped event distribution (Architecture Plan §IV.10,
				// F23): every fulfillment that reached a given milestone
				// state also carries the M2 event types a real pack would
				// have produced getting there — the M1 proof measured
				// `event_type` vs `created_at` selectivity with exactly one
				// event type in the table; M2 shipped 13 more, and this is
				// what makes that re-measurement mean something.
				$actor_id = $assignee_id ?? 1;
				$reached  = static fn( array $states ): bool => in_array( $state, $states, true );

				if ( $reached( array( 'packing', 'packed', 'shipped', 'delivered', 'completed' ) ) ) {
					$event_rows[] = array( $fulfillment_row_index, 'items.picked', 'user', $actor_id, 'Seed', wp_json_encode( array( 'lines' => $item_count ) ), null, hash( 'sha256', $order_id . ':items.picked' ), $state_entered_at );
				}

				if ( $reached( array( 'packed', 'shipped', 'delivered', 'completed' ) ) ) {
					$event_rows[] = array( $fulfillment_row_index, 'items.packed', 'user', $actor_id, 'Seed', wp_json_encode( array( 'lines' => $item_count ) ), null, hash( 'sha256', $order_id . ':items.packed' ), $state_entered_at );
					$event_rows[] = array( $fulfillment_row_index, 'shipment.created', 'user', $actor_id, 'Seed', wp_json_encode( array( 'carrier_id' => 'postnord' ) ), null, hash( 'sha256', $order_id . ':shipment.created' ), $state_entered_at );
					$event_rows[] = array( $fulfillment_row_index, 'package.created', 'user', $actor_id, 'Seed', wp_json_encode( array( 'seq' => 1 ) ), null, hash( 'sha256', $order_id . ':package.created' ), $state_entered_at );
					$event_rows[] = array( $fulfillment_row_index, 'shipment.updated', 'user', $actor_id, 'Seed', wp_json_encode( array( 'tracking_number' => 'TRACK-' . $order_id ) ), null, hash( 'sha256', $order_id . ':shipment.updated' ), $state_entered_at );
					$event_rows[] = array( $fulfillment_row_index, 'document.rendered', 'user', $actor_id, 'Seed', wp_json_encode( array( 'doc_type' => 'packing_slip' ) ), null, hash( 'sha256', $order_id . ':document.rendered' ), $state_entered_at );

					$shipment_status = $reached( array( 'shipped', 'delivered', 'completed' ) ) ? 'shipped' : 'pending';

					$shipment_rows[] = array(
						$fulfillment_row_index,
						'postnord',
						'standard',
						'TRACK-' . $order_id,
						null,
						$shipment_status,
						'shipped' === $shipment_status ? $state_entered_at : null,
						$reached( array( 'delivered', 'completed' ) ) ? $state_entered_at : null,
						$state_entered_at,
					);

					$package_rows[] = array( count( $shipment_rows ) - 1, 1, wp_rand( 200, 5000 ), wp_rand( 100, 400 ), wp_rand( 100, 400 ), wp_rand( 50, 300 ), null, $state_entered_at );

					if ( 0 === self::$packed_fulfillment_id ) {
						self::$packed_fulfillment_id = $fulfillment_row_index; // Resolved to a real id alongside $ids below.
						self::$known_tracking_number = 'TRACK-' . $order_id;
					}
				}

				if ( $reached( array( 'shipped', 'delivered', 'completed' ) ) ) {
					$event_rows[] = array( $fulfillment_row_index, 'shipment.shipped', 'user', $actor_id, 'Seed', wp_json_encode( array() ), null, hash( 'sha256', $order_id . ':shipment.shipped' ), $state_entered_at );
				}

				if ( $reached( array( 'delivered', 'completed' ) ) ) {
					$event_rows[] = array( $fulfillment_row_index, 'shipment.delivered', 'user', $actor_id, 'Seed', wp_json_encode( array() ), null, hash( 'sha256', $order_id . ':shipment.delivered' ), $state_entered_at );
				}
			}
		}

		// A fixed, explicitly-synthetic slice of "entered packed/shipped
		// today" events so the Dashboard's today-throughput counters have a
		// realistic non-zero value — not derived from natural date
		// arithmetic (D21 needs volume/shape realism, not a full discrete
		// -event simulation of same-day fulfillment).
		$today        = gmdate( 'Y-m-d H:i:s' );
		$forced_today = 0;

		foreach ( $event_rows as &$event_row ) {
			if ( $forced_today >= 150 ) {
				break;
			}

			$payload = json_decode( $event_row[5], true );

			if ( in_array( $payload['to'] ?? null, array( 'packed', 'shipped' ), true ) && 0 === wp_rand( 0, 9 ) ) {
				$event_row[8] = $today;
				++$forced_today;
			}
		}
		unset( $event_row );

		self::bulk_insert(
			$fulfillments_table,
			array( 'order_id', 'order_source', 'warehouse_id', 'workflow', 'state', 'previous_state', 'return_to_state', 'exception_reason', 'priority', 'assignee_type', 'assignee_id', 'version', 'order_number_snapshot', 'customer_name_snapshot', 'item_count', 'created_at', 'state_entered_at', 'completed_at' ),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ),
			$rows
		);

		// Resolve each item/event's fulfillment_row_index into the id MySQL
		// actually assigned (AUTO_INCREMENT), in insertion order.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $fulfillments_table is Schema-built, never user input.
		$ids = $wpdb->get_col( "SELECT id FROM {$fulfillments_table} ORDER BY id ASC" );

		$item_rows = array_map(
			static function ( $row ) use ( $ids ) {
				$row[0] = (int) $ids[ $row[0] ];
				return $row;
			},
			$item_rows
		);

		$event_rows = array_map(
			static function ( $row ) use ( $ids ) {
				$row[0] = (int) $ids[ $row[0] ];
				return $row;
			},
			$event_rows
		);

		self::bulk_insert(
			$items_table,
			array( 'fulfillment_id', 'order_item_id', 'product_id', 'variation_id', 'sku_snapshot', 'name_snapshot', 'qty_ordered', 'qty_picked', 'qty_packed' ),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d' ),
			$item_rows
		);

		self::bulk_insert(
			$events_table,
			array( 'fulfillment_id', 'event_type', 'actor_type', 'actor_id', 'actor_label_snapshot', 'payload', 'prev_hash', 'hash', 'created_at' ),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ),
			$event_rows
		);

		self::$packed_fulfillment_id = (int) $ids[ self::$packed_fulfillment_id ];

		$shipments_table = Schema::table( Schema::SHIPMENTS );
		$packages_table  = Schema::table( Schema::PACKAGES );

		$shipment_rows = array_map(
			static function ( $row ) use ( $ids ) {
				$row[0] = (int) $ids[ $row[0] ];
				return $row;
			},
			$shipment_rows
		);

		self::bulk_insert(
			$shipments_table,
			array( 'fulfillment_id', 'carrier_id', 'service', 'tracking_number', 'tracking_url', 'status', 'shipped_at', 'delivered_at', 'created_at' ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			$shipment_rows
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $shipments_table is Schema-built, never user input.
		$shipment_ids = $wpdb->get_col( "SELECT id FROM {$shipments_table} ORDER BY id ASC" );

		$package_rows = array_map(
			static function ( $row ) use ( $shipment_ids ) {
				$row[0] = (int) $shipment_ids[ $row[0] ];
				return $row;
			},
			$package_rows
		);

		self::bulk_insert(
			$packages_table,
			array( 'shipment_id', 'seq', 'weight_grams', 'length_mm', 'width_mm', 'height_mm', 'tracking_number', 'created_at' ),
			array( '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s' ),
			$package_rows
		);
	}

	/**
	 * Chunked multi-row INSERT — plain `$wpdb->insert()` one row at a time
	 * is far too slow at this volume.
	 *
	 * @param string                   $table   Target table.
	 * @param array<int, string>       $columns Column names, in row order.
	 * @param array<int, string>       $formats `%d`/`%s` per column.
	 * @param array<int, array<mixed>> $rows Row values, in column order.
	 */
	private static function bulk_insert( string $table, array $columns, array $formats, array $rows ): void {
		global $wpdb;

		if ( array() === $rows ) {
			return;
		}

		$column_list = implode( ', ', $columns );

		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '(' . implode( ', ', $formats ) . ')' ) );
			$values       = array();

			foreach ( $chunk as $row ) {
				array_push( $values, ...$row );
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table/$column_list are Schema-built/fixed, never user input; $placeholders is built exclusively from %-format tokens, real and bound via $values below.
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} ({$column_list}) VALUES {$placeholders}", $values ) );
		}
	}
}
