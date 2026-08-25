<?php
/**
 * Dynamic client registration (RFC 7591).
 *
 * AI clients register themselves anonymously before the first sign-in. Only
 * public clients are issued (PKCE is mandatory, no secrets), and registration is
 * rate limited per address so an open endpoint cannot fill the table.
 *
 * @package mcp-connect
 */

namespace MCP_OAuth\Register;

use MCP_OAuth\Storage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

const MAX_REDIRECT_URIS      = 10;
const MAX_CLIENTS_PER_IP     = 20;
const REGISTRATIONS_PER_HOUR = 10;

/**
 * Hook the route.
 */
function register(): void {
	add_action( 'rest_api_init', __NAMESPACE__ . '\register_routes' );
}

/**
 * Register the REST route.
 */
function register_routes(): void {
	register_rest_route(
		\MCP_OAuth\REST_NAMESPACE,
		'/register',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\handle',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Remote address of the request, empty when unknown.
 */
function client_ip(): string {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/**
 * Fixed-window per-address throttle shared by the unauthenticated endpoints.
 *
 * @param string $bucket Endpoint name.
 * @param int    $limit  Requests allowed per window.
 * @param int    $window Window length in seconds.
 */
function within_rate_limit( string $bucket, int $limit, int $window ): bool {
	$ip = client_ip();
	if ( '' === $ip ) {
		return true;
	}
	$key     = 'mcp_oauth_rl_' . $bucket . '_' . hash( 'sha256', $ip );
	$current = (int) get_transient( $key );
	if ( $current >= $limit ) {
		return false;
	}
	set_transient( $key, $current + 1, $window );
	return true;
}

/**
 * Schemes a redirect URI may use besides https and loopback http.
 *
 * @return string[]
 */
function allowed_schemes(): array {
	/**
	 * Filters the custom URL schemes accepted in redirect URIs (for native apps).
	 *
	 * @param string[] $schemes Scheme names.
	 */
	return (array) apply_filters(
		'mcp_oauth_redirect_uri_schemes',
		array( 'claude', 'cursor', 'vscode', 'vscode-insiders', 'chatgpt', 'codex', 'windsurf', 'zed', 'ms-onboarding-claude-code' )
	);
}

/**
 * Whether an address is loopback.
 */
function is_loopback( string $host ): bool {
	$host = trim( $host, '[]' );
	return 'localhost' === $host || '::1' === $host || 0 === strpos( $host, '127.' );
}

/**
 * Whether a redirect URI may be registered.
 *
 * https anywhere public, http only on loopback (RFC 8252 native apps), and the
 * custom schemes of known desktop clients. Private and link-local address
 * literals are refused unless this is a local environment.
 */
function is_allowed_redirect_uri( string $uri ): bool {
	if ( false !== strpos( $uri, '#' ) ) {
		return false; // RFC 6749 §3.1.2: no fragment.
	}
	$parts = wp_parse_url( $uri );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) ) {
		return false;
	}
	$scheme = strtolower( $parts['scheme'] );
	$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
	$local  = 'local' === wp_get_environment_type();

	if ( 'http' === $scheme ) {
		return is_loopback( $host );
	}
	if ( 'https' !== $scheme ) {
		return in_array( $scheme, allowed_schemes(), true );
	}
	if ( '' === $host ) {
		return false;
	}
	if ( is_loopback( $host ) || '.local' === substr( $host, -6 ) || '.localhost' === substr( $host, -10 ) ) {
		return $local;
	}
	$ip = filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP );
	if ( false !== $ip ) {
		return $local || false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}
	return true;
}

/**
 * RFC 7591 error response.
 */
function error( string $code, string $description, int $status = 400 ): WP_REST_Response {
	return new WP_REST_Response(
		array(
			'error'             => $code,
			'error_description' => $description,
		),
		$status
	);
}

/**
 * Handle a registration request.
 */
function handle( WP_REST_Request $request ): WP_REST_Response {
	if ( ! within_rate_limit( 'register', REGISTRATIONS_PER_HOUR, HOUR_IN_SECONDS ) ) {
		return error( 'too_many_requests', 'Too many registrations from this address.', 429 );
	}
	if ( '' !== client_ip() && Storage\client_count_for_ip( client_ip() ) >= MAX_CLIENTS_PER_IP ) {
		return error( 'too_many_requests', 'Too many clients registered from this address.', 429 );
	}

	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		return error( 'invalid_client_metadata', 'Request body must be a JSON object.' );
	}

	$name = sanitize_text_field( (string) ( $body['client_name'] ?? '' ) );
	if ( '' === $name ) {
		$name = __( 'Unnamed AI client', 'mcp-oauth' );
	}
	$name = mb_substr( $name, 0, 191 );

	$client_uri = isset( $body['client_uri'] ) && is_string( $body['client_uri'] ) ? esc_url_raw( $body['client_uri'] ) : '';

	$auth_method = (string) ( $body['token_endpoint_auth_method'] ?? 'none' );
	if ( 'none' !== $auth_method ) {
		return error( 'invalid_client_metadata', 'Only public clients (token_endpoint_auth_method "none") are supported.' );
	}

	$grants = $body['grant_types'] ?? array( 'authorization_code' );
	if ( ! is_array( $grants ) || array_diff( $grants, array( 'authorization_code', 'refresh_token' ) ) ) {
		return error( 'invalid_client_metadata', 'Only the authorization_code and refresh_token grants are supported.' );
	}

	$uris = $body['redirect_uris'] ?? null;
	if ( ! is_array( $uris ) || ! $uris ) {
		return error( 'invalid_redirect_uri', 'redirect_uris must be a non-empty array.' );
	}
	if ( count( $uris ) > MAX_REDIRECT_URIS ) {
		return error( 'invalid_redirect_uri', 'Too many redirect_uris.' );
	}
	$clean = array();
	foreach ( $uris as $uri ) {
		$uri = is_string( $uri ) ? trim( $uri ) : '';
		if ( '' === $uri || strlen( $uri ) > 2048 || ! is_allowed_redirect_uri( $uri ) ) {
			return error( 'invalid_redirect_uri', 'redirect_uri not allowed: ' . $uri );
		}
		$clean[] = $uri;
	}
	$clean = array_values( array_unique( $clean ) );

	$client_id = Storage\create_client( $name, $clean, $client_uri, client_ip() );

	$response = new WP_REST_Response(
		array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => time(),
			'client_name'                => $name,
			'client_uri'                 => $client_uri,
			'redirect_uris'              => $clean,
			'token_endpoint_auth_method' => 'none',
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
		),
		201
	);
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}
