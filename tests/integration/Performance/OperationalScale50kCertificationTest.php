<?php
/**
 * P3 Operational Certification — ~50k fulfillment scale (5× M2 10k proof).
 *
 * Not part of CI. Records wall timings and EXPLAIN index behavior.
 * Does **not** invent pass/fail millisecond budgets — budgets from the 10k
 * proof are reference-only for comparison.
 *
 *   docker run --rm --network mpcf-test-net -v "$PWD":/app -w /app \
 *     -e WP_DB_HOST=mpcf-test-db -e WP_DB_NAME=wordpress_test \
 *     -e WP_DB_USER=root -e WP_DB_PASS=root \
 *     mpcf-test-runner:latest \
 *     vendor/bin/phpunit -c phpunit-performance-50k.xml.dist
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Performance;

use MPCF\Application\DashboardService;
use MPCF\Application\Diagnostics\DoctorService;
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
use MPCF\Infrastructure\Diagnostics\Checkers\ConsistencyChecker;
use MPCF\Infrastructure\Diagnostics\Checkers\SchemaChecker;
use MPCF\Infrastructure\Diagnostics\DefaultCheckerRegistryFactory;
use MPCF\Infrastructure\SystemClock;
use MPCF\Plugin;
use WP_UnitTestCase;

/**
 * Seeds ~50k fulfillments once; measures P3-A surfaces; asserts indexed plans only.
 */
final class OperationalScale50kCertificationTest extends WP_UnitTestCase {

	private const TOTAL_FULFILLMENTS = 50000;
	private const ITERATIONS         = 10;
	private const SCALE              = 5;

	/** @var array<string, int> */
	private const STATE_COUNTS = array(
		'completed'   => 35000,
		'cancelled'   => 2500,
		'queued'      => 3500,
		'picking'     => 2500,
		'picked'      => 2000,
		'packing'     => 1750,
		'packed'      => 1500,
		'shipped'     => 750,
		'delivered'   => 300,
		'problem'     => 100,
		'waiting'     => 75,
		'backordered' => 25,
	);

	/** @var array<string, list<string>> */
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

	/** @var list<string> */
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

	/** @var bool */
	private static bool $seeded = false;

	/** @var int */
	private static int $packed_fulfillment_id = 0;

	/** @var string */
	private static string $known_tracking_number = '';

	/** @var int */
	private static int $search_order_id = 0;

	/** @var list<string> */
	private static array $timing_log = array();

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( self::$seeded ) {
			return;
		}
		$sum = array_sum( self::STATE_COUNTS );
		self::assertSame( self::TOTAL_FULFILLMENTS, $sum, 'STATE_COUNTS must sum to TOTAL_FULFILLMENTS.' );
		Plugin::activate();
		self::seed_dataset();
		self::$seeded = true;
	}

	protected function setUp(): void {
		self::assertTrue( self::$seeded );
	}

	public static function tearDownAfterClass(): void {
		global $wpdb;
		$path = dirname( __DIR__, 3 ) . '/docs/certification/p3-perf-50k-timings.log';
		if ( array() !== self::$timing_log ) {
			file_put_contents( $path, implode( "\n", self::$timing_log ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- certification artifact write.
		}
		foreach ( Schema::all_tables() as $table ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		self::$seeded = false;
		parent::tearDownAfterClass();
	}

	public function test_dataset_scale_and_capacity_signal(): void {
		global $wpdb;
		$table        = Schema::table( Schema::FULFILLMENTS );
		$events_table = Schema::table( Schema::EVENTS );
		$count        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Schema-built table name.
		$events       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Schema-built table name.
		self::log_line( sprintf( 'dataset fulfillments=%d events=%d scale=%d', $count, $events, self::SCALE ) );
		self::assertSame( self::TOTAL_FULFILLMENTS, $count, 'Seed must persist all fulfillments; check bulk_insert / max_allowed_packet.' );
		self::assertGreaterThan( 100000, $events );
	}

	public function test_queue_initial_load(): void {
		$queue = $this->queue_service();
		$query = new FulfillmentQuery( self::OPEN_STATES, null, null, null, 'created_at', 'DESC', 1, 20 );
		$this->measure( 'P3-A Queue initial load (open states LIMIT 20)', static fn() => $queue->list( $query ) );
	}

	public function test_queue_search_customer_prefix(): void {
		// Lookup shape must stay indexed (prefix LIKE). The follow-on listing
		// query with a very large IN (...) list may EXPLAIN as ALL — record
		// that separately; do not treat it as a seed/schema defect.
		( new WpdbSearchQuery() )->search( 'Alex' );
		global $wpdb;
		$explain = $wpdb->get_results( 'EXPLAIN ' . $wpdb->last_query, ARRAY_A ) ?? array();
		foreach ( $explain as $row ) {
			self::assertNotSame( 'ALL', $row['type'] ?? null, 'customer-name lookup must not table-scan' );
		}
		$queue = $this->queue_service();
		$this->measure(
			'P3-A Queue search customer prefix Alex (end-to-end)',
			static fn() => $queue->list( new FulfillmentQuery( array(), null, null, null, 'created_at', 'DESC', 1, 20 ), 'Alex' ),
			false
		);
	}

	public function test_workspace_open_packed(): void {
		self::assertGreaterThan( 0, self::$packed_fulfillment_id );
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
		$this->measure(
			'P3-A Workspace open packed fulfillment',
			static function () use ( $fulfillments, $items, $events, $shipping, $notes, $id ) {
				$fulfillments->find( $id );
				$items->find_for_fulfillment( $id );
				$events->recent_for_fulfillment( $id, 5 );
				$shipping->list_for_fulfillment( $id );
				$notes->find_for_fulfillment( $id );
			}
		);
	}

	public function test_dashboard_and_orders_adjacent_counts(): void {
		$dashboard = new DashboardService( new WpdbFulfillmentRepository(), new WpdbEventRepository(), new SystemClock() );
		$this->measure( 'P3-A Dashboard open_count', static fn() => $dashboard->open_count( StandardWorkflow::definition() ) );
		$this->measure( 'P3-A Dashboard packed_today', static fn() => $dashboard->packed_today() );
	}

	public function test_doctor_full_run(): void {
		$doctor = new DoctorService( DefaultCheckerRegistryFactory::create() );
		$this->measure(
			'P3-A doctor full run',
			static function () use ( $doctor ) {
				return $doctor->run();
			}
		);
	}

	public function test_validate_schema_and_consistency(): void {
		$schema      = new SchemaChecker();
		$consistency = new ConsistencyChecker();
		$this->measure( 'P3-A validate schema (SchemaChecker)', static fn() => $schema->run() );
		$this->measure( 'P3-A validate consistency (ConsistencyChecker)', static fn() => $consistency->run() );
	}

	public function test_numeric_search_indexed(): void {
		$queue  = $this->queue_service();
		$term   = (string) self::$search_order_id;
		$result = $this->measure(
			'P3-A Queue numeric search ' . $term,
			static fn() => $queue->list( new FulfillmentQuery( array(), null, null, null, 'created_at', 'DESC', 1, 20 ), $term )
		);
		self::assertGreaterThan( 0, $result['result']->total() );
	}

	/**
	 * @param string   $label          Label.
	 * @param callable $call           Call.
	 * @param bool     $assert_indexed Whether to fail on EXPLAIN type=ALL.
	 * @return array{result: mixed, timings: list<float>}
	 */
	private function measure( string $label, callable $call, bool $assert_indexed = true ): array {
		global $wpdb;
		$timings = array();
		$result  = null;
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$start     = microtime( true );
			$result    = $call();
			$timings[] = ( microtime( true ) - $start ) * 1000.0;
		}
		$explain = array();
		if ( is_string( $wpdb->last_query ) && '' !== $wpdb->last_query && false === stripos( (string) $wpdb->last_query, 'EXPLAIN' ) && false === stripos( (string) $wpdb->last_query, 'SHOW ' ) ) {
			$explain = $wpdb->get_results( 'EXPLAIN ' . $wpdb->last_query, ARRAY_A ) ?? array();
			if ( $assert_indexed ) {
				foreach ( $explain as $row ) {
					self::assertNotSame( 'ALL', $row['type'] ?? null, "{$label}: full table scan — {$wpdb->last_query}" );
				}
			}
		}
		sort( $timings );
		$cold = $timings[0];
		$p50  = $timings[ (int) floor( self::ITERATIONS * 0.5 ) ];
		$p95  = $timings[ max( 0, (int) floor( self::ITERATIONS * 0.95 ) - 1 ) ];
		$mem  = memory_get_peak_usage( true );
		$line = sprintf(
			'%s | cold=%.2fms p50=%.2fms p95=%.2fms peak_mem=%d key=%s type=%s',
			$label,
			$cold,
			$p50,
			$p95,
			$mem,
			$explain[0]['key'] ?? 'n/a',
			$explain[0]['type'] ?? 'n/a'
		);
		self::log_line( $line );
		fwrite( STDERR, "\n[p3-perf] {$line}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		return array(
			'result'  => $result,
			'timings' => $timings,
		);
	}

	private function queue_service(): QueueService {
		return new QueueService( new WpdbFulfillmentRepository(), new WpdbSearchQuery() );
	}

	private static function log_line( string $line ): void {
		self::$timing_log[] = $line;
	}

	private static function seed_dataset(): void {
		global $wpdb;
		foreach ( Schema::all_tables() as $table ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		$fulfillments_table = Schema::table( Schema::FULFILLMENTS );
		$items_table        = Schema::table( Schema::FULFILLMENT_ITEMS );
		$events_table       = Schema::table( Schema::EVENTS );
		$shipments_table    = Schema::table( Schema::SHIPMENTS );
		$packages_table     = Schema::table( Schema::PACKAGES );

		$order_id   = 600000;
		$name_index = 0;

		foreach ( self::STATE_COUNTS as $state => $count ) {
			$rows          = array();
			$item_rows     = array();
			$event_rows    = array();
			$shipment_rows = array();
			$package_rows  = array();
			$is_open       = in_array( $state, self::OPEN_STATES, true );

			for ( $i = 0; $i < $count; $i++ ) {
				++$order_id;
				if ( 0 === self::$search_order_id && 'queued' === $state && 0 === $i ) {
					self::$search_order_id = $order_id;
				}

				$age_days         = $is_open
					? ( in_array( $state, self::EXCEPTION_STATES, true ) ? wp_rand( 1, 20 ) : wp_rand( 0, 14 ) )
					: wp_rand( 3, 120 );
				$created_at       = gmdate( 'Y-m-d H:i:s', time() - ( $age_days * DAY_IN_SECONDS ) - wp_rand( 0, 3600 ) );
				$state_entered_at = gmdate( 'Y-m-d H:i:s', min( time(), strtotime( $created_at ) + wp_rand( 0, 2 * DAY_IN_SECONDS ) ) );
				$completed_at     = in_array( $state, array( 'completed', 'cancelled' ), true ) ? $state_entered_at : null;
				$assignee_id      = ( ! $is_open || wp_rand( 0, 99 ) >= 35 ) ? wp_rand( 501, 520 ) : null;
				$item_count       = wp_rand( 1, 5 );
				$name             = self::NAME_POOL[ $name_index % count( self::NAME_POOL ) ];
				$path_so_far      = self::STATE_PATH[ $state ];
				$previous_state   = count( $path_so_far ) > 1 ? $path_so_far[ count( $path_so_far ) - 2 ] : null;
				++$name_index;

				$rows[]                = array(
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
					$from         = 0 === $step_index ? null : $path[ $step_index - 1 ];
					$is_last      = ( count( $path ) - 1 ) === $step_index;
					$event_time   = $is_last
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

				$actor_id = $assignee_id ?? 1;
				$reached  = static fn( array $states ): bool => in_array( $state, $states, true );
				if ( $reached( array( 'packing', 'packed', 'shipped', 'delivered', 'completed' ) ) ) {
					$event_rows[] = array( $fulfillment_row_index, 'items.picked', 'user', $actor_id, 'Seed', wp_json_encode( array( 'lines' => $item_count ) ), null, hash( 'sha256', $order_id . ':items.picked' ), $state_entered_at );
				}
				if ( $reached( array( 'packed', 'shipped', 'delivered', 'completed' ) ) ) {
					$event_rows[]    = array( $fulfillment_row_index, 'items.packed', 'user', $actor_id, 'Seed', wp_json_encode( array( 'lines' => $item_count ) ), null, hash( 'sha256', $order_id . ':items.packed' ), $state_entered_at );
					$event_rows[]    = array( $fulfillment_row_index, 'shipment.created', 'user', $actor_id, 'Seed', wp_json_encode( array( 'carrier_id' => 'postnord' ) ), null, hash( 'sha256', $order_id . ':shipment.created' ), $state_entered_at );
					$event_rows[]    = array( $fulfillment_row_index, 'package.created', 'user', $actor_id, 'Seed', wp_json_encode( array( 'seq' => 1 ) ), null, hash( 'sha256', $order_id . ':package.created' ), $state_entered_at );
					$event_rows[]    = array( $fulfillment_row_index, 'shipment.updated', 'user', $actor_id, 'Seed', wp_json_encode( array( 'tracking_number' => 'TRACK-' . $order_id ) ), null, hash( 'sha256', $order_id . ':shipment.updated' ), $state_entered_at );
					$event_rows[]    = array( $fulfillment_row_index, 'document.rendered', 'user', $actor_id, 'Seed', wp_json_encode( array( 'doc_type' => 'packing_slip' ) ), null, hash( 'sha256', $order_id . ':document.rendered' ), $state_entered_at );
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
					$package_rows[]  = array( count( $shipment_rows ) - 1, 1, wp_rand( 200, 5000 ), wp_rand( 100, 400 ), wp_rand( 100, 400 ), wp_rand( 50, 300 ), null, $state_entered_at );
				}
				if ( $reached( array( 'shipped', 'delivered', 'completed' ) ) ) {
					$event_rows[] = array( $fulfillment_row_index, 'shipment.shipped', 'user', $actor_id, 'Seed', wp_json_encode( array() ), null, hash( 'sha256', $order_id . ':shipment.shipped' ), $state_entered_at );
				}
				if ( $reached( array( 'delivered', 'completed' ) ) ) {
					$event_rows[] = array( $fulfillment_row_index, 'shipment.delivered', 'user', $actor_id, 'Seed', wp_json_encode( array() ), null, hash( 'sha256', $order_id . ':shipment.delivered' ), $state_entered_at );
				}
			}

			self::bulk_insert(
				$fulfillments_table,
				array( 'order_id', 'order_source', 'warehouse_id', 'workflow', 'state', 'previous_state', 'return_to_state', 'exception_reason', 'priority', 'assignee_type', 'assignee_id', 'version', 'order_number_snapshot', 'customer_name_snapshot', 'item_count', 'created_at', 'state_entered_at', 'completed_at' ),
				array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ),
				$rows
			);
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$fulfillments_table} ORDER BY id DESC LIMIT %d", count( $rows ) ) );
			$ids = array_reverse( array_map( 'intval', $ids ) );

			$item_rows  = array_map(
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

			if ( array() !== $shipment_rows ) {
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
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$shipment_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$shipments_table} ORDER BY id DESC LIMIT %d", count( $shipment_rows ) ) );
				$shipment_ids = array_reverse( array_map( 'intval', $shipment_ids ) );
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

			fwrite( STDERR, sprintf( "\n[p3-seed] state=%s rows=%d order_id=%d\n", $state, count( $rows ), $order_id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Schema-built table name.
		self::$packed_fulfillment_id = (int) $wpdb->get_var( "SELECT id FROM {$fulfillments_table} WHERE state='packed' ORDER BY id ASC LIMIT 1" );
		$shipments_table             = Schema::table( Schema::SHIPMENTS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Schema-built table name; fulfillment_id bound via prepare.
		self::$known_tracking_number = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT tracking_number FROM {$shipments_table} WHERE fulfillment_id=%d LIMIT 1",
				self::$packed_fulfillment_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		self::log_line(
			sprintf(
				'seed complete packed_id=%d tracking=%s search_order=%d',
				self::$packed_fulfillment_id,
				self::$known_tracking_number,
				self::$search_order_id
			)
		);
	}

	/**
	 * @param string                   $table   Table.
	 * @param array<int, string>       $columns Columns.
	 * @param array<int, string>       $formats Formats.
	 * @param array<int, array<mixed>> $rows    Rows.
	 * @throws \RuntimeException When INSERT fails.
	 */
	private static function bulk_insert( string $table, array $columns, array $formats, array $rows ): void {
		global $wpdb;
		if ( array() === $rows ) {
			return;
		}
		$column_list = implode( ', ', $columns );
		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '(' . implode( ', ', $formats ) . ')' ) );
			$values       = array();
			foreach ( $chunk as $row ) {
				array_push( $values, ...$row );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery -- Schema-built table/columns; placeholders built from formats only.
			$ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} ({$column_list}) VALUES {$placeholders}", $values ) );
			if ( false === $ok ) {
				fwrite( STDERR, "\n[p3-seed] INSERT failed on {$table}: {$wpdb->last_error}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message for test harness diagnostics only.
				throw new \RuntimeException( 'bulk_insert failed: ' . $wpdb->last_error );
			}
		}
	}
}
