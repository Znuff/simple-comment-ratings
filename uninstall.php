<?php
/**
 * Runs on plugin uninstall. Removes all plugin data.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scr" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scr_counts" );

delete_option( 'scr_options' );
delete_option( 'scr_db_version' );
