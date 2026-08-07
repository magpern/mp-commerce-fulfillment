<?php
/**
 * Bounded M8 dogfood smoke for bind-mounted wpcli.
 *
 * Usage: wp eval-file wp-content/plugins/mp-commerce-fulfillment/bin/smoke-m8-wave.php
 *
 * @package MPCommerceFulfillment
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file inside WordPress.\n" );
	exit( 1 );
}

if ( ! class_exists( \MPCF\Infrastructure\Database\Migrator::class ) ) {
	fwrite( STDERR, "MPCF not loaded.\n" );
	exit( 1 );
}

$migrator = new \MPCF\Infrastructure\Database\Migrator();
$migrator->maybe_migrate();

$version  = $migrator->current_version();
$settings = new \MPCF\Settings();
$schema   = (int) $settings->get()['schema_version'];

$waves   = \MPCF\Infrastructure\Database\Schema::table( \MPCF\Infrastructure\Database\Schema::WAVES );
$members = \MPCF\Infrastructure\Database\Schema::table( \MPCF\Infrastructure\Database\Schema::WAVE_MEMBERS );

global $wpdb;
$waves_ok   = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $waves ) ) ) === $waves;
$members_ok = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $members ) ) ) === $members;

$registry = new \MPCF\Documents\DocumentTypeRegistry();
$has_doc  = null !== $registry->get( \MPCF\Engine\DocumentAssembler\WavePickingListAssembler::DOC_TYPE );
$payload  = \MPCF\Domain\Barcode\BarcodePayload::is_known_type( \MPCF\Domain\Barcode\BarcodePayload::TYPE_WAVE );

echo "M8 smoke\n";
echo 'migrator=' . $version . ( 7 === $version ? " OK\n" : " FAIL\n" );
echo 'settings_schema=' . $schema . ( $schema >= 9 ? " OK\n" : " FAIL\n" );
echo 'table_waves=' . ( $waves_ok ? "OK\n" : "FAIL\n" );
echo 'table_wave_members=' . ( $members_ok ? "OK\n" : "FAIL\n" );
echo 'doc_wave_picking_list=' . ( $has_doc ? "OK\n" : "FAIL\n" );
echo 'barcode_W=' . ( $payload ? "OK\n" : "FAIL\n" );
echo 'plugin_version=' . ( defined( 'MPCF_VERSION' ) ? MPCF_VERSION : '?' ) . "\n";
echo 'wave_max_members=' . $settings->wave_max_members() . "\n";

$ok = ( 7 === $version )
	&& ( $schema >= 9 )
	&& $waves_ok
	&& $members_ok
	&& $has_doc
	&& $payload
	&& ( defined( 'MPCF_VERSION' ) && '0.8.0' === MPCF_VERSION );
exit( $ok ? 0 : 1 );
