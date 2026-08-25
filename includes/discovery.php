<?php
/**
 * OAuth discovery documents.
 *
 * Serves RFC 9728 protected-resource metadata and RFC 8414 authorization-server
 * metadata on the `/.well-known/` paths an MCP client probes. Both the "insert"
 * forms at the domain root (`/.well-known/oauth-protected-resource{resource path}`)
 * and the "append" forms under the site's own path are answered, so subdirectory
 * installs work as long as they own the domain root, and root installs always do.
 *
 * @package mcp-connect
 */

namespace MCP_OAuth\Discovery;

use MCP_OAuth\Servers;

defined( 'ABSPATH' ) || exit;

const PROTECTED_RESOURCE   = '/.well-known/oauth-protected-resource';
const AUTHORIZATION_SERVER = '/.well-known/oauth-authorization-server';
const OPENID_CONFIGURATION = '/.well-known/openid-configuration';

/**
 * Hook the request interceptor.
 */
function register(): void {
	add_action( 'init', __NAMESPACE__ . '\maybe_serve', 5 );
}

/**
 * The issuer identifier: the site itself.
 */
function issuer(): string {
	return untrailingslashit( home_url() );
}

/**
 * The site's path component without a trailing slash ('' for root installs).
 */
function home_path(): string {
	return rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
}

/**
 * The protected-resource metadata URL advertised in WWW-Authenticate challenges
 * for a server: the insert form, the one current MCP clients construct.
 *
 * @param \WP\MCP\Core\McpServer $server Server.
 */
function protected_resource_metadata_url( $server ): string {
	$resource = Servers\resource( $server );
	$parts    = wp_parse_url( $resource );
	$origin   = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	$path     = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
	if ( ! empty( $parts['query'] ) ) {
		// Plain permalinks: the resource is ?rest_route=…, which has no path to insert; fall back to the append form.
		return $origin . home_path() . PROTECTED_RESOURCE;
	}
	return $origin . PROTECTED_RESOURCE . $path;
}

/**
 * Protected-resource metadata (RFC 9728).
 *
 * @param \WP\MCP\Core\McpServer $server Server.
 */
function protected_resource_document( $server ): array {
	return array(
		'resource'                 => Servers\resource( $server ),
		'resource_name'            => $server->get_server_name(),
		'authorization_servers'    => array( issuer() ),
		'bearer_methods_supported' => array( 'header' ),
		'scopes_supported'         => array( \MCP_OAuth\SCOPE ),
	);
}

/**
 * Authorization-server metadata (RFC 8414).
 *
 * The authorization endpoint lives on wp-login.php rather than under the REST API so
 * the sign-in step uses the ordinary WordPress cookie, which keeps working on
 * sites where REST cookie authentication is disabled.
 */
function authorization_server_document(): array {
	$base = rest_url( \MCP_OAuth\REST_NAMESPACE );
	return array(
		'issuer'                                     => issuer(),
		'authorization_endpoint'                     => \MCP_OAuth\Authorize\endpoint_url(),
		'token_endpoint'                             => $base . '/token',
		'registration_endpoint'                      => $base . '/register',
		'revocation_endpoint'                        => $base . '/revoke',
		'response_types_supported'                   => array( 'code' ),
		'response_modes_supported'                   => array( 'query' ),
		'grant_types_supported'                      => array( 'authorization_code', 'refresh_token' ),
		'code_challenge_methods_supported'           => array( 'S256' ),
		'token_endpoint_auth_methods_supported'      => array( 'none' ),
		'revocation_endpoint_auth_methods_supported' => array( 'none' ),
		'scopes_supported'                           => array( \MCP_OAuth\SCOPE ),
		'service_documentation'                      => 'https://modelcontextprotocol.io/specification/draft/basic/authorization',
	);
}

/**
 * Answer a discovery request, if this is one.
 */
function maybe_serve(): void {
	$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( ! is_string( $path ) || false === strpos( $path, '/.well-known/' ) ) {
		return;
	}
	$path = rtrim( $path, '/' );
	$home = home_path();

	// Authorization-server metadata: append forms under the site path, insert forms at the root.
	$as_paths = array_unique(
		array(
			$home . AUTHORIZATION_SERVER,
			$home . OPENID_CONFIGURATION,
			AUTHORIZATION_SERVER . $home,
			OPENID_CONFIGURATION . $home,
		)
	);
	if ( in_array( $path, $as_paths, true ) ) {
		serve( authorization_server_document() );
	}

	// Protected-resource metadata, append form: describes the primary server.
	if ( $path === $home . PROTECTED_RESOURCE ) {
		$server = Servers\primary();
		if ( $server ) {
			serve( protected_resource_document( $server ) );
		}
		return;
	}

	// Insert form: /.well-known/oauth-protected-resource/<REST path of a server>.
	if ( 0 === strpos( $path, PROTECTED_RESOURCE . '/' ) ) {
		$server = Servers\for_url_path( substr( $path, strlen( PROTECTED_RESOURCE ) ) );
		if ( $server ) {
			serve( protected_resource_document( $server ) );
		}
	}
}

/**
 * Send a JSON document and stop.
 */
function serve( array $document ): void {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	header( 'Content-Type: application/json; charset=UTF-8' );
	header( 'Access-Control-Allow-Origin: *' );
	header( 'X-Robots-Tag: noindex' );
	if ( 'OPTIONS' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, MCP-Protocol-Version' );
		exit;
	}
	echo wp_json_encode( $document, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}
