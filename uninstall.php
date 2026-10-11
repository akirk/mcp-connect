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

// Keep the shared WebMCP options while the standalone plugin is installed.
if ( ! is_file( WP_PLUGIN_DIR . '/webmcp-abilities/webmcp-abilities.php' ) ) {
	foreach ( array( 'wmcp_enabled', 'wmcp_discovery_public', 'wmcp_exposed_tools', 'wmcp_tool_visibility', 'wmcp_visibility_migrated', 'wmcp_bridge_cache_version' ) as $option ) {
		delete_option( $option );
	}
}
