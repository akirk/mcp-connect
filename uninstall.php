<?php
/**
 * Remove everything the plugin stored.
 *
 * @package mcp-connect
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
foreach ( array( 'clients', 'codes', 'tokens' ) as $table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'mcp_oauth_' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
}
delete_option( 'mcp_oauth_schema_version' );
delete_option( 'mcp_oauth_hidden_abilities' );
delete_option( 'mcp_oauth_direct_tools' );
wp_clear_scheduled_hook( 'mcp_oauth_gc' );
