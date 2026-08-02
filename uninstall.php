<?php
/**
 * Uninstall handler.
 *
 * Retention is all-or-nothing (invariant I12). With
 * `remove_data_on_uninstall` disabled — the default — this file is a no-op:
 * every option, table, role, capability, scheduled action and user
 * preference survives so a later reinstall resumes exactly where it left
 * off.
 *
 * Every kind removed here comes from `MPCF\PersistedKeys::inventory()`, not
 * hardcoded, so the two can never silently drift apart
 * (`PersistedKeysInventoryTest` / `UninstallPolicyGuardTest` bind this file
 * to that inventory and to docs/PERSISTED_DATA.md). Every step is written
 * to be safe to run more than once: `DROP TABLE IF EXISTS`, `delete_option()`
 * on an already-missing option, and Action Scheduler's own
 * `as_unschedule_all_actions()` against an already-empty group are all
 * no-ops the second time, which is what makes re-running this file (e.g. a
 * retried uninstall request) safe.
 *
 * @package MPCommerceFulfillment
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$mpcf_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $mpcf_autoload ) ) {
	require_once $mpcf_autoload;
}

$mpcf_settings = \MPCF\Settings::sanitize( get_option( \MPCF\Settings::OPTION ) );

if ( empty( $mpcf_settings['remove_data_on_uninstall'] ) ) {
	return;
}

\MPCF\Capabilities::uninstall();

// Cancel this plugin's own outstanding scheduled work before tearing down
// what it would have acted on. Scoped to this plugin's own Action
// Scheduler group only — Action Scheduler's tables themselves are
// WooCommerce's, never dropped or altered here, only the rows this plugin
// filed under its own group. function_exists() guards the case WooCommerce
// (and therefore Action Scheduler) is no longer active by the time this
// plugin is uninstalled — invariant I10, never a fatal during uninstall.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( \MPCF\PersistedKeys::action_scheduler_groups() as $mpcf_as_group ) {
		as_unschedule_all_actions( '', array(), $mpcf_as_group );
	}
}

global $wpdb;
foreach ( \MPCF\PersistedKeys::tables() as $mpcf_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $mpcf_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
}

foreach ( \MPCF\PersistedKeys::option_keys() as $mpcf_option ) {
	delete_option( $mpcf_option );
}

foreach ( \MPCF\PersistedKeys::user_meta_keys() as $mpcf_user_meta_key ) {
	delete_metadata( 'user', 0, $mpcf_user_meta_key, '', true );
}
