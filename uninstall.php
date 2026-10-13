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

// Keep the shared options even if the standalone plugin's directory was renamed.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$mcp_oauth_standalone_installed = false;
foreach ( get_plugins() as $mcp_oauth_plugin ) {
	if ( 'webmcp-abilities' === ( $mcp_oauth_plugin['TextDomain'] ?? '' ) ) {
		$mcp_oauth_standalone_installed = true;
		break;
	}
}
if ( ! $mcp_oauth_standalone_installed ) {
	foreach ( array( 'wmcp_enabled', 'wmcp_discovery_public', 'wmcp_exposed_tools', 'wmcp_tool_visibility', 'wmcp_visibility_migrated', 'wmcp_bridge_cache_version' ) as $option ) {
		delete_option( $option );
	}
}
