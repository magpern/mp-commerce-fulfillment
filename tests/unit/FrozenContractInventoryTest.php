<?php
/**
 * P2 certification: ACTIVE architecture freeze vs shipped code inventory.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use MPCF\Capabilities;
use MPCF\Infrastructure\Database\Migrator;
use MPCF\Infrastructure\Database\Schema;
use MPCF\Settings;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Ensures freeze inventory claims match released surfaces — no silent drift.
 */
final class FrozenContractInventoryTest extends TestCase {

	/**
	 * Migrator TARGET remains 8 under the ACTIVE freeze baseline.
	 */
	public function test_migrator_target_is_eight(): void {
		self::assertSame( 8, Migrator::TARGET );
	}

	/**
	 * Frozen capability slugs from the ACTIVE inventory remain defined.
	 */
	public function test_frozen_capability_slugs_exist(): void {
		$expected = array(
			Capabilities::VIEW_QUEUE,
			Capabilities::PROCESS_FULFILLMENTS,
			Capabilities::MANAGE_SHIPMENTS,
			Capabilities::ADD_NOTES,
			Capabilities::CAPTURE_PHOTOS,
			Capabilities::DELETE_PHOTOS,
			Capabilities::RENDER_DOCUMENTS,
			Capabilities::CANCEL_FULFILLMENT,
			Capabilities::VIEW_AUDIT,
			Capabilities::VIEW_ANALYTICS,
			Capabilities::VIEW_OPERATOR_STATS,
			Capabilities::MANAGE_SETTINGS,
		);

		foreach ( $expected as $cap ) {
			self::assertMatchesRegularExpression( '/^mpcf_/', $cap );
		}

		self::assertSame( 'mpcf_warehouse_operator', Capabilities::ROLE_OPERATOR );
		self::assertSame( 'mpcf_warehouse_lead', Capabilities::ROLE_LEAD );
	}

	/**
	 * Schema owns every table named by TARGET 8 freeze semantics.
	 */
	public function test_schema_table_constants_cover_target_eight(): void {
		$required = array(
			Schema::FULFILLMENTS,
			Schema::FULFILLMENT_ITEMS,
			Schema::EVENTS,
			Schema::NOTES,
			Schema::SHIPMENTS,
			Schema::PACKAGES,
			Schema::PACKAGE_ITEMS,
			Schema::DOCUMENTS,
			Schema::MEDIA,
			Schema::WAVES,
			Schema::WAVE_MEMBERS,
			Schema::ANALYTICS_DAILY,
		);

		foreach ( $required as $name ) {
			self::assertStringStartsWith( 'mpcf_', $name );
		}

		self::assertCount( 12, Schema::all_tables() );
	}

	/**
	 * Settings option and schema version remain the shipped persisted contract.
	 */
	public function test_settings_option_and_schema_version(): void {
		self::assertSame( 'mpcf_settings', Settings::OPTION );
		self::assertSame( 9, Settings::SCHEMA_VERSION );
		$defaults = Settings::defaults();
		self::assertArrayHasKey( 'notification_strategy', $defaults );
		self::assertArrayHasKey( 'photos_required', $defaults );
		self::assertArrayHasKey( 'wave_max_members', $defaults );
		self::assertArrayHasKey( 'remove_data_on_uninstall', $defaults );
	}

	/**
	 * Deferred WP extension hooks must not appear as do_action/apply_filters yet.
	 */
	public function test_deferred_hooks_are_not_registered_in_src(): void {
		$root  = dirname( __DIR__, 2 ) . '/src';
		$files = new RegexIterator(
			new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ),
			'/\.php$/'
		);

		$forbidden = array(
			"do_action( 'mpcf_event'",
			'do_action( "mpcf_event"',
			"apply_filters( 'mpcf_workflows'",
			'apply_filters( "mpcf_workflows"',
			"apply_filters( 'mpcf_intake_should_create'",
			'apply_filters( "mpcf_intake_should_create"',
		);

		$hits = array();
		foreach ( $files as $file ) {
			$contents = (string) file_get_contents( (string) $file );
			foreach ( $forbidden as $needle ) {
				if ( str_contains( $contents, $needle ) ) {
					$hits[] = (string) $file . ' :: ' . $needle;
				}
			}
		}

		self::assertSame( array(), $hits, 'Deferred hooks must stay deferred until explicitly shipped.' );
	}

	/**
	 * Shipped public filters remain callable names in source.
	 */
	public function test_shipped_public_filters_are_present(): void {
		$root = dirname( __DIR__, 2 ) . '/src';
		$need = array(
			'mpcf_workspace_flags',
			'mpcf_document_types',
			'mpcf_document_template',
			'mpcf_document_model',
			'mpcf_carriers',
		);

		$blob  = '';
		$files = new RegexIterator(
			new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ),
			'/\.php$/'
		);
		foreach ( $files as $file ) {
			$blob .= (string) file_get_contents( (string) $file );
		}

		foreach ( $need as $hook ) {
			self::assertTrue(
				str_contains( $blob, "'" . $hook . "'" ) || str_contains( $blob, '"' . $hook . '"' ),
				"Missing shipped hook reference: {$hook}"
			);
		}
	}

	/**
	 * Frozen CLI command classes remain in the tree.
	 */
	public function test_frozen_cli_command_classes_exist(): void {
		$cli = dirname( __DIR__, 2 ) . '/src/Cli';
		foreach ( array( 'DoctorCommand.php', 'ValidateCommand.php', 'RepairCommand.php', 'AuditCommand.php', 'AnalyticsCommand.php', 'BackfillCommand.php' ) as $file ) {
			self::assertFileExists( $cli . '/' . $file );
		}
	}

	/**
	 * Version triad parity for the certification baseline release line.
	 */
	public function test_version_triad_is_zero_ten_zero_until_v1_tag(): void {
		$header = (string) file_get_contents( dirname( __DIR__, 2 ) . '/mp-commerce-fulfillment.php' );
		self::assertMatchesRegularExpression( '/\* Version:\s*0\.10\.0/', $header );
		self::assertMatchesRegularExpression( "/define\(\s*'MPCF_VERSION',\s*'0\.10\.0'\s*\)/", $header );
		$readme = (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' );
		self::assertMatchesRegularExpression( '/Stable tag:\s*0\.10\.0/', $readme );
	}
}
