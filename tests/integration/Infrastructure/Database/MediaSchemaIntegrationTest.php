<?php
/**
 * Schema migration integration tests for mpcf_media.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Integration\Infrastructure\Database;

use MPCF\Infrastructure\Database\Migrator;
use MPCF\Infrastructure\Database\Schema;
use WP_UnitTestCase;

/**
 * Proves step 6 creates mpcf_media with the Part VIII column/index shape.
 */
final class MediaSchemaIntegrationTest extends WP_UnitTestCase {

	public function test_migrate_to_six_creates_media_table_with_required_shape(): void {
		global $wpdb;

		$table = Schema::table( Schema::MEDIA );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		update_option( Migrator::OPTION, 5, true );

		$migrator = new Migrator();
		$migrator->migrate();

		self::assertSame( 8, $migrator->current_version() );

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		self::assertNotNull( $exists );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is Schema-built.
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
		self::assertIsArray( $columns );

		$by_name = array();
		foreach ( $columns as $column ) {
			$by_name[ $column['Field'] ] = $column;
		}

		foreach ( array(
			'id',
			'fulfillment_id',
			'package_id',
			'kind',
			'file_path',
			'thumb_path',
			'mime',
			'bytes',
			'sha256',
			'processing_version',
			'width',
			'height',
			'seq',
			'captured_by',
			'created_at',
			'deleted_at',
			'purged_at',
		) as $required ) {
			self::assertArrayHasKey( $required, $by_name, "Missing column {$required}" );
		}

		self::assertSame( 'NO', $by_name['package_id']['Null'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is Schema-built.
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		self::assertIsArray( $indexes );

		$index_names = array_unique( array_map( static fn( array $row ): string => (string) $row['Key_name'], $indexes ) );

		foreach ( array( 'PRIMARY', 'fulfillment_id', 'package_id', 'fulfillment_deleted', 'package_deleted', 'fulfillment_seq' ) as $index ) {
			self::assertContains( $index, $index_names, "Missing index {$index}" );
		}
	}

	public function test_step_six_is_idempotent(): void {
		$migrator = new Migrator();
		$migrator->migrate();
		$migrator->migrate();

		self::assertSame( 8, $migrator->current_version() );

		global $wpdb;
		$table  = Schema::table( Schema::MEDIA );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		self::assertNotNull( $exists );
	}
}
