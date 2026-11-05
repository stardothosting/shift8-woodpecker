<?php
/**
 * Remove plugin data on uninstall.
 *
 * @package Shift8\Woodpecker
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'shift8_woodpecker_settings' );
delete_option( 'shift8_woodpecker_last_pipeline' );
delete_option( 'shift8_woodpecker_scheduled' );
delete_option( 'shift8_woodpecker_db_version' );
delete_transient( 'shift8_woodpecker_trigger_lock' );
wp_clear_scheduled_hook( 'shift8_woodpecker_run_scheduled' );

// The audit trail is removed with the plugin's data.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}shift8_woodpecker_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- fixed table name on uninstall.
