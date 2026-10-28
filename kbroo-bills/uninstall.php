<?php
/**
 * Removes the plugin's tables and settings when it is deleted from WordPress.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}kbroo_items" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}kbroo_oneoffs" );
delete_option( 'kbroo_bills_settings' );
delete_option( 'kbroo_bills_db_version' );

remove_role( 'kbroo_bills_viewer' );
$kbroo_admin = get_role( 'administrator' );
if ( $kbroo_admin ) {
	$kbroo_admin->remove_cap( 'view_kbroo_bills' );
}
