<?php
/**
 * Uninstall handler.
 *
 * Retention is all-or-nothing (invariant I12). With
 * `remove_data_on_uninstall` disabled — the default — this file is a no-op:
 * every option, table, role and capability survives so a later reinstall
 * resumes exactly where it left off.
 *
 * Later milestones extend this file as they introduce tables and the
 * protected media directory (see docs/PERSISTED_DATA.md, kept in sync with
 * this file by UninstallPolicyGuardTest).
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

delete_option( \MPCF\Settings::OPTION );
