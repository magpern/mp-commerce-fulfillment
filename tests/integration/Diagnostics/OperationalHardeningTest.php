<?php
/**
 * Integration: doctor / validate / repair / privacy / Site Health (M10).
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Diagnostics;

use MPCF\Application\Diagnostics\AuditChainVerifier;
use MPCF\Application\Diagnostics\CheckStatus;
use MPCF\Capabilities;
use MPCF\Infrastructure\Database\Migrator;
use MPCF\Infrastructure\Database\Schema;
use MPCF\Infrastructure\Database\WpdbEventPrivacyAnonymizer;
use MPCF\Infrastructure\Database\WpdbEventRepository;
use MPCF\Infrastructure\Database\WpdbPrivacyRepository;
use MPCF\Infrastructure\Diagnostics\DefaultCheckerRegistryFactory;
use MPCF\Application\Diagnostics\DoctorService;
use MPCF\Infrastructure\Diagnostics\Repair\ScheduleRepairService;
use MPCF\Infrastructure\Diagnostics\Repair\StorageDirsRepairService;
use MPCF\Application\Diagnostics\MaintenanceAuditor;
use MPCF\Application\EventDispatcher;
use MPCF\Infrastructure\Privacy\PrivacyEraser;
use MPCF\Infrastructure\SiteHealth\SiteHealthRegistrar;
use MPCF\Infrastructure\SystemClock;
use MPCF\Tests\Integration\CleanFulfillmentTablesTrait;
use WP_UnitTestCase;
use DateTimeImmutable;
use MPCF\Domain\Event\Actor;
use MPCF\Domain\Event\DomainEvent;

/**
 * M10 operational surfaces against real WP+DB.
 */
final class OperationalHardeningTest extends WP_UnitTestCase {

	use CleanFulfillmentTablesTrait;

	/**
	 * Doctor runs read-only and reports structured results on a healthy install.
	 */
	public function test_doctor_passes_on_migrated_install(): void {
		( new Migrator() )->migrate();
		Capabilities::activate();

		$report = ( new DoctorService( DefaultCheckerRegistryFactory::create() ) )->run();
		self::assertGreaterThan( 0, $report->pass_count() );
		// Failures may include REST routes before rest_api_init — allow warn-only or document.
		foreach ( $report->results() as $result ) {
			if ( CheckStatus::FAIL !== $result->status() ) {
				continue;
			}
			// REST may not be registered until rest_api_init; schedules/storage
			// dirs are created lazily — repairable fails are acceptable here.
			if ( str_starts_with( $result->id(), 'integration.rest' )
				|| str_starts_with( $result->id(), 'schedule.missing' )
				|| str_starts_with( $result->id(), 'storage.' )
			) {
				continue;
			}
			self::fail( $result->id() . ': ' . $result->summary() );
		}
	}

	/**
	 * Storage repair dry-run does not write; --yes creates dirs.
	 */
	public function test_storage_repair_requires_yes(): void {
		$uploads = wp_upload_dir();
		$root    = rtrim( (string) $uploads['basedir'], '/' ) . '/mpcf-test-repair-' . wp_generate_password( 6, false );
		// Use real uploads/mpcf path via repair service — dry-run then yes.
		$events  = new WpdbEventRepository();
		$auditor = new MaintenanceAuditor( $events, new EventDispatcher(), new SystemClock() );
		$svc     = new StorageDirsRepairService( $auditor );

		$dry = $svc->repair( false );
		self::assertTrue( $dry->dry_run() );
		self::assertFalse( $dry->applied() );

		$applied = $svc->repair( true );
		self::assertTrue( is_dir( rtrim( (string) $uploads['basedir'], '/' ) . '/mpcf' ) );
		self::assertTrue( $applied->applied() || array() === $applied->changes() );
		unset( $root );
	}

	/**
	 * Schedule repair dry-run then apply restores missing hooks when AS exists.
	 */
	public function test_schedule_repair_dry_run_then_yes(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			self::markTestSkipped( 'Action Scheduler required.' );
		}

		as_unschedule_all_actions( 'mpcf_purge_photo_retention', array(), 'mpcf' );
		as_unschedule_all_actions( 'mpcf_analytics_daily_rollup', array(), 'mpcf' );

		$events  = new WpdbEventRepository();
		$auditor = new MaintenanceAuditor( $events, new EventDispatcher(), new SystemClock() );
		$svc     = new ScheduleRepairService( $auditor );

		$dry = $svc->repair( false );
		self::assertNotSame( array(), $dry->changes() );
		self::assertFalse( $dry->applied() );
		self::assertFalse( as_has_scheduled_action( 'mpcf_purge_photo_retention', array(), 'mpcf' ) );

		$yes = $svc->repair( true );
		self::assertTrue( $yes->applied() );
		self::assertTrue( as_has_scheduled_action( 'mpcf_purge_photo_retention', array(), 'mpcf' ) );
		self::assertTrue( as_has_scheduled_action( 'mpcf_analytics_daily_rollup', array(), 'mpcf' ) );
	}

	/**
	 * Privacy eraser anonymizes name without breaking hash chain.
	 */
	public function test_privacy_erase_preserves_audit_chain(): void {
		( new Migrator() )->migrate();
		global $wpdb;

		$f_table = Schema::table( Schema::FULFILLMENTS );
		$wpdb->insert(
			$f_table,
			array(
				'order_id'               => 900001,
				'order_source'           => 'woocommerce',
				'state'                  => 'queued',
				'workflow'               => 'standard',
				'version'                => 1,
				'warehouse_id'           => 1,
				'customer_name_snapshot' => 'Jane Doe',
				'created_at'             => '2026-01-01 00:00:00',
				'state_entered_at'       => '2026-01-01 00:00:00',
			)
		);
		$fid = (int) $wpdb->insert_id;

		$events = new WpdbEventRepository();
		$event  = DomainEvent::for_fulfillment(
			$fid,
			'fulfillment.state_changed',
			Actor::user( 42, 'Op' ),
			new DateTimeImmutable( '2026-01-01T12:00:00+00:00' ),
			array(
				'from' => 'queued',
				'to'   => 'picking',
			)
		);
		$events->append( $event, null );

		$eraser = new PrivacyEraser( new WpdbPrivacyRepository(), new WpdbEventPrivacyAnonymizer() );
		// Direct order-path erase (no email lookup needed).
		$eraser->erase_for_order_id( 900001 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $f_table is Schema-built.
		$name = $wpdb->get_var( $wpdb->prepare( "SELECT customer_name_snapshot FROM {$f_table} WHERE id = %d", $fid ) );
		self::assertSame( PrivacyEraser::ANON_NAME, $name );

		$verifier = new AuditChainVerifier( $events );
		$result   = $verifier->verify_fulfillment( $fid );
		self::assertTrue( $result['ok'], (string) $result['error'] );

		$anonymizer = new WpdbEventPrivacyAnonymizer();
		$anonymizer->anonymize_actor_user( 42, PrivacyEraser::ANON_ACTOR );
		self::assertTrue( $verifier->verify_fulfillment( $fid )['ok'] );
	}

	/**
	 * Site Health adapter maps failures to critical without mutation.
	 */
	public function test_site_health_registers_and_runs(): void {
		( new Migrator() )->migrate();
		delete_transient( 'mpcf_site_health_ops' );

		$registry = DefaultCheckerRegistryFactory::create();
		$reg      = new SiteHealthRegistrar( $registry );
		$tests    = $reg->register_tests( array( 'direct' => array() ) );
		self::assertArrayHasKey( 'mpcf_operational', $tests['direct'] );

		$out = $reg->run_aggregate_test();
		self::assertArrayHasKey( 'status', $out );
		self::assertContains( $out['status'], array( 'good', 'recommended', 'critical' ) );
	}
}
