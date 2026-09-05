<?php
/**
 * Plugin Name:       MCP Connect
 * Plugin URI:        https://github.com/akirk/mcp-connect
 * Description:       Lets AI clients (Claude.ai, Claude Code, ChatGPT, Codex, Cursor, VS Code …) connect to this site's MCP servers with a normal sign-in. Bundles the WordPress MCP Adapter, adds the OAuth 2.1 server it lacks, and a Connect page with ready-made links and snippets.
 * Version:           0.1.0+95ef86bbc09c
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Alex Kirk
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mcp-oauth
 *
 * @package mcp-connect
 */

namespace MCP_OAuth;

defined( 'ABSPATH' ) || exit;

define( 'MCP_OAUTH_VERSION', '0.1.0' );
define( 'MCP_OAUTH_FILE', __FILE__ );
define( 'MCP_OAUTH_DIR', plugin_dir_path( __FILE__ ) );

/**
 * The bundled MCP Adapter and its dependencies.
 *
 * This is the Jetpack autoloader, which every plugin that bundles the adapter
 * shares: it collects the versioned class manifests of all active plugins and
 * loads the newest copy of each class once. A separately installed MCP Adapter
 * plugin therefore does not collide with this one — whichever of the two is
 * newer serves the `WP\MCP\*` classes to both.
 *
 * It needs WordPress itself, so define `MCP_OAUTH_AUTOLOAD` as false to load
 * the adapter some other way (the unit tests do, to keep their stubs).
 */
if ( ( ! defined( 'MCP_OAUTH_AUTOLOAD' ) || false !== MCP_OAUTH_AUTOLOAD ) && is_readable( MCP_OAUTH_DIR . 'vendor/autoload_packages.php' ) ) {
	require_once MCP_OAUTH_DIR . 'vendor/autoload_packages.php';
}

/**
 * REST namespace that carries the token, registration and revocation endpoints.
 */
const REST_NAMESPACE = 'mcp-oauth/v1';

/**
 * The only scope this server issues. Access is governed by the MCP server's own
 * permission checks and each ability's permission callback, acting as the user
 * who authorized the client.
 */
const SCOPE = 'mcp';

require MCP_OAUTH_DIR . 'includes/servers.php';
require MCP_OAUTH_DIR . 'includes/storage.php';
require MCP_OAUTH_DIR . 'includes/discovery.php';
require MCP_OAUTH_DIR . 'includes/register.php';
require MCP_OAUTH_DIR . 'includes/authorize.php';
require MCP_OAUTH_DIR . 'includes/token.php';
require MCP_OAUTH_DIR . 'includes/middleware.php';
require MCP_OAUTH_DIR . 'includes/abilities.php';
require MCP_OAUTH_DIR . 'includes/health.php';
require MCP_OAUTH_DIR . 'includes/clients.php';
require MCP_OAUTH_DIR . 'includes/admin.php';

/**
 * Whether the MCP Adapter is loaded.
 */
function adapter_available(): bool {
	return class_exists( '\WP\MCP\Core\McpAdapter' );
}

/**
 * Where the MCP Adapter classes in use come from, for Site Health.
 */
function adapter_source(): string {
	if ( ! adapter_available() ) {
		return __( 'not loaded', 'mcp-oauth' );
	}
	try {
		$file = ( new \ReflectionClass( '\WP\MCP\Core\McpAdapter' ) )->getFileName();
	} catch ( \ReflectionException $e ) {
		return __( 'unknown', 'mcp-oauth' );
	}
	$path = str_replace( wp_normalize_path( WP_PLUGIN_DIR ) . '/', '', wp_normalize_path( (string) $file ) );

	return 0 === strpos( wp_normalize_path( (string) $file ), wp_normalize_path( MCP_OAUTH_DIR ) )
		/* translators: %s: path to the loaded MCP Adapter file, relative to the plugin directory. */
		? sprintf( __( 'bundled with MCP Connect (%s)', 'mcp-oauth' ), $path )
		/* translators: %s: path to the loaded MCP Adapter file, relative to the plugin directory. */
		: sprintf( __( 'separately installed plugin (%s)', 'mcp-oauth' ), $path );
}

/**
 * Start the bundled MCP Adapter, unless a separately installed one already did.
 *
 * The adapter's own plugin file boots it while plugins load, before this runs,
 * and both its `Plugin` and its `McpAdapter` class are singletons — so this is
 * a no-op whenever the standalone plugin is active and working, and starts the
 * adapter when it is absent (or present as a source checkout with no vendor
 * directory of its own, in which case it bails out silently).
 */
function boot_adapter(): void {
	if ( class_exists( '\WP\MCP\Plugin' ) ) {
		\WP\MCP\Plugin::instance();
	}
}

/**
 * Whether the OAuth endpoints may be exposed at all.
 *
 * Authorization codes and bearer tokens must never travel over plain HTTP on a
 * public site, so the endpoints only exist on HTTPS sites and local environments.
 */
function transport_allowed(): bool {
	$allowed = 0 === strpos( strtolower( home_url() ), 'https://' ) || 'local' === wp_get_environment_type();

	/**
	 * Filters whether the OAuth endpoints are exposed.
	 *
	 * @param bool $allowed True on HTTPS sites and local environments.
	 */
	return (bool) apply_filters( 'mcp_oauth_transport_allowed', $allowed );
}

/**
 * Whether this WordPress runs inside WordPress Playground (PHP compiled to
 * WebAssembly). Such a site lives in a browser tab or a local process, so no
 * AI client can reach it from the outside.
 */
function is_playground(): bool {
	$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
	return false !== strpos( $software, 'PHP.wasm' )
		&& false !== strpos( ABSPATH, '/wordpress' )
		&& function_exists( 'post_message_to_js' );
}

/**
 * Capability a user needs to authorize an AI client. Defaults to the same
 * capability the MCP Adapter requires for transport access.
 */
function authorize_capability(): string {
	/**
	 * Filters the capability required to authorize an AI client.
	 *
	 * @param string $capability Default 'read'.
	 */
	$capability = apply_filters( 'mcp_oauth_authorize_capability', 'read' );
	return is_string( $capability ) && '' !== $capability ? $capability : 'read';
}

/**
 * Boot the plugin once every plugin is loaded.
 */
function boot(): void {
	Admin\register();
	Health\register();
	boot_adapter();

	if ( ! adapter_available() ) {
		return;
	}

	Storage\maybe_install();
	Servers\register();
	Abilities\register();

	if ( ! transport_allowed() ) {
		return;
	}

	Discovery\register();
	Register\register();
	Authorize\register();
	Token\register();
	Middleware\register();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\boot', 20 );

register_activation_hook( __FILE__, __NAMESPACE__ . '\Storage\maybe_install' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\Storage\unschedule' );
