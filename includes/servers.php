<?php
/**
 * Knowledge about the MCP servers registered with the MCP Adapter.
 *
 * Every MCP server is one OAuth protected resource (RFC 9728), identified by its
 * REST URL. Tokens are bound to that URL and only accepted on that route.
 *
 * @package mcp-connect
 */

namespace MCP_OAuth\Servers;

defined( 'ABSPATH' ) || exit;

/**
 * Make sure the adapter's own abilities get registered.
 *
 * The adapter registers `mcp-adapter/*` on `wp_abilities_api_init`, but only
 * adds that hook inside `init()`, which runs on `rest_api_init` — or, outside
 * REST, on demand from here. When another plugin has used the Abilities API
 * earlier in the request, that action has already fired, the adapter's hook
 * never runs, and core refuses registrations made outside the action. So hook
 * the action at plugin load, register the abilities from inside it, and retire
 * the adapter's own callbacks.
 */
function register(): void {
	add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\register_adapter_category', 5 );
	add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\register_adapter_abilities', 5 );
}

/**
 * Whether the adapter's default server (and with it its abilities) is wanted.
 */
function default_server_wanted(): bool {
	return \MCP_OAuth\adapter_available() && (bool) apply_filters( 'mcp_adapter_create_default_server', true );
}

/**
 * Register the adapter's ability category from inside the categories action.
 */
function register_adapter_category(): void {
	if ( ! default_server_wanted() ) {
		return;
	}
	$adapter = \WP\MCP\Core\McpAdapter::instance();
	if ( function_exists( 'wp_get_ability_category' ) && ! wp_get_ability_category( 'mcp-adapter' ) ) {
		$adapter->register_default_category();
	}
	remove_action( 'wp_abilities_api_categories_init', array( $adapter, 'register_default_category' ) );
}

/**
 * Register the adapter's abilities from inside the abilities action.
 */
function register_adapter_abilities(): void {
	if ( ! default_server_wanted() ) {
		return;
	}
	$adapter = \WP\MCP\Core\McpAdapter::instance();
	if ( function_exists( 'wp_get_ability' ) && ! wp_get_ability( 'mcp-adapter/discover-abilities' ) ) {
		$adapter->register_default_abilities();
	}
	remove_action( 'wp_abilities_api_init', array( $adapter, 'register_default_abilities' ) );
}

/**
 * All registered MCP servers, keyed by server id.
 *
 * The adapter only initializes itself on REST requests, so outside of those
 * (discovery documents, the admin page) it is initialized here on demand.
 * `init()` is idempotent.
 *
 * @return \WP\MCP\Core\McpServer[]
 */
function all(): array {
	if ( ! \MCP_OAuth\adapter_available() ) {
		return array();
	}
	$adapter = \WP\MCP\Core\McpAdapter::instance();
	$adapter->init();
	return $adapter->get_servers();
}

/**
 * The server new connections should use: the adapter's default server if it
 * exists, otherwise the first registered one.
 *
 * @return \WP\MCP\Core\McpServer|null
 */
function primary() {
	$servers = all();
	if ( isset( $servers['mcp-adapter-default-server'] ) ) {
		return $servers['mcp-adapter-default-server'];
	}
	return $servers ? reset( $servers ) : null;
}

/**
 * The tools, resources and prompts a server hands to a connected client.
 *
 * This is the adapter's own view, after every filter has run — what a client
 * actually receives from `tools/list`, not what the site asked for.
 *
 * @param \WP\MCP\Core\McpServer $server Server.
 * @return array<int, array{type:string,name:string,ability:string,description:string}>
 */
function inventory( $server ): array {
	// The listing getters hand back protocol DTOs; the singular ones hand back the
	// adapter's wrappers, which are the only place the backing ability is named.
	$sources = array(
		'tool'     => array( 'get_tools', 'get_mcp_tool' ),
		'resource' => array( 'get_resources', 'get_mcp_resource' ),
		'prompt'   => array( 'get_prompts', 'get_mcp_prompt' ),
	);
	$rows = array();
	foreach ( $sources as $type => $source ) {
		foreach ( $server->{$source[0]}() as $key => $dto ) {
			$wrapper = method_exists( $server, $source[1] ) ? $server->{$source[1]}( (string) $key ) : null;
			$rows[]  = component( $type, (string) $key, $dto, $wrapper );
		}
	}
	return $rows;
}

/**
 * One row of the inventory.
 *
 * A tool or prompt knows its own protocol name; a resource is filed under its
 * URI, which is what identifies it to a client.
 *
 * @param string      $type    One of tool, resource, prompt.
 * @param string      $key     The key the server filed the component under.
 * @param object      $dto     The protocol DTO the server advertises.
 * @param object|null $wrapper The adapter's McpTool, McpResource or McpPrompt, when it has one.
 * @return array{type:string,name:string,ability:string,description:string}
 */
function component( string $type, string $key, $dto, $wrapper = null ): array {
	$context = $wrapper && method_exists( $wrapper, 'get_observability_context' ) ? $wrapper->get_observability_context() : array();
	$name    = $key;
	if ( 'resource' !== $type && method_exists( $dto, 'getName' ) ) {
		$name = (string) $dto->getName();
	}
	return array(
		'type'        => $type,
		'name'        => $name,
		'ability'     => isset( $context['ability_name'] ) && is_string( $context['ability_name'] ) ? $context['ability_name'] : '',
		'description' => method_exists( $dto, 'getDescription' ) ? (string) $dto->getDescription() : '',
	);
}

/**
 * The REST route (as WP_REST_Request::get_route() reports it) of a server.
 *
 * @param \WP\MCP\Core\McpServer $server Server.
 */
function route( $server ): string {
	return '/' . trim( $server->get_server_route_namespace(), '/' ) . '/' . trim( $server->get_server_route(), '/' );
}

/**
 * The resource identifier (RFC 8707 audience) of a server: its REST URL.
 *
 * @param \WP\MCP\Core\McpServer $server Server.
 */
function resource( $server ): string {
	return rest_url( ltrim( route( $server ), '/' ) );
}

/**
 * Normalize a resource URL for comparison.
 */
function normalize( string $resource ): string {
	return rtrim( trim( $resource ), '/' );
}

/**
 * The server a resource identifier names, or null when no server matches.
 *
 * @return \WP\MCP\Core\McpServer|null
 */
function for_resource( string $requested ) {
	$requested = normalize( $requested );
	if ( '' === $requested ) {
		return null;
	}
	foreach ( all() as $server ) {
		if ( normalize( resource( $server ) ) === $requested ) {
			return $server;
		}
	}
	return null;
}

/**
 * The server that owns a matched REST route, or null for any other route.
 *
 * @return \WP\MCP\Core\McpServer|null
 */
function for_route( string $rest_route ) {
	$rest_route = '/' . trim( $rest_route, '/' );
	foreach ( all() as $server ) {
		$own = route( $server );
		if ( $rest_route === $own || 0 === strpos( $rest_route, $own . '/' ) ) {
			return $server;
		}
	}
	return null;
}

/**
 * The server a REST URL path (as sent in a discovery request) belongs to.
 *
 * @return \WP\MCP\Core\McpServer|null
 */
function for_url_path( string $path ) {
	$path = rtrim( $path, '/' );
	foreach ( all() as $server ) {
		$own = (string) wp_parse_url( resource( $server ), PHP_URL_PATH );
		if ( '' !== $own && rtrim( $own, '/' ) === $path ) {
			return $server;
		}
	}
	return null;
}
