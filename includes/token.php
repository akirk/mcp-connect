<?php
/**
 * Token (RFC 6749 §4.1.3, §6, PKCE RFC 7636) and revocation (RFC 7009) endpoints.
 *
 * @package mcp-connect
 */

namespace MCP_OAuth\Token;

use MCP_OAuth\Register;
use MCP_OAuth\Servers;
use MCP_OAuth\Storage;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

const REQUESTS_PER_MINUTE = 60;

/**
 * Hook the routes.
 */
function register(): void {
	add_action( 'rest_api_init', __NAMESPACE__ . '\register_routes' );
}

/**
 * Register the REST routes.
 */
function register_routes(): void {
	register_rest_route(
		\MCP_OAuth\REST_NAMESPACE,
		'/token',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\handle_token',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		\MCP_OAuth\REST_NAMESPACE,
		'/revoke',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\handle_revoke',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * A token-endpoint response with the caching headers RFC 6749 §5.1 requires.
 */
function respond( array $body, int $status = 200 ): WP_REST_Response {
	$response = new WP_REST_Response( $body, $status );
	$response->header( 'Cache-Control', 'no-store' );
	$response->header( 'Pragma', 'no-cache' );
	return $response;
}

/**
 * RFC 6749 §5.2 error response.
 */
function error( string $code, string $description, int $status = 400 ): WP_REST_Response {
	return respond(
		array(
			'error'             => $code,
			'error_description' => $description,
		),
		$status
	);
}

/**
 * The client id of a request: from the body, or from HTTP Basic credentials
 * (some clients send public client ids that way).
 */
function request_client_id( WP_REST_Request $request ): string {
	$client_id = (string) $request->get_param( 'client_id' );
	if ( '' !== $client_id ) {
		return $client_id;
	}
	$header = (string) $request->get_header( 'authorization' );
	if ( 0 === stripos( $header, 'Basic ' ) ) {
		$decoded = base64_decode( trim( substr( $header, 6 ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( is_string( $decoded ) && false !== strpos( $decoded, ':' ) ) {
			return rawurldecode( strstr( $decoded, ':', true ) );
		}
	}
	return '';
}

/**
 * Verify a PKCE S256 code verifier against the stored challenge.
 */
function verifier_matches( string $verifier, string $challenge ): bool {
	if ( ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) ) {
		return false;
	}
	$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	return hash_equals( $challenge, $computed );
}

/**
 * Handle a token request.
 */
function handle_token( WP_REST_Request $request ): WP_REST_Response {
	if ( ! Register\within_rate_limit( 'token', REQUESTS_PER_MINUTE, MINUTE_IN_SECONDS ) ) {
		return error( 'temporarily_unavailable', 'Too many requests.', 429 );
	}

	$client_id = request_client_id( $request );
	$client    = Storage\get_client( $client_id );
	if ( ! $client ) {
		return error( 'invalid_client', 'Unknown client.', 401 );
	}

	$grant_type = (string) $request->get_param( 'grant_type' );
	switch ( $grant_type ) {
		case 'authorization_code':
			return redeem_code( $request, $client );
		case 'refresh_token':
			return refresh( $request, $client );
		default:
			return error( 'unsupported_grant_type', 'Supported grant types: authorization_code, refresh_token.' );
	}
}

/**
 * Exchange an authorization code for tokens.
 */
function redeem_code( WP_REST_Request $request, array $client ): WP_REST_Response {
	$code     = (string) $request->get_param( 'code' );
	$verifier = (string) $request->get_param( 'code_verifier' );
	if ( '' === $code || '' === $verifier ) {
		return error( 'invalid_request', 'code and code_verifier are required.' );
	}

	$row = Storage\consume_code( $code );
	if ( ! $row || ! hash_equals( (string) $row['client_id'], $client['client_id'] ) ) {
		return error( 'invalid_grant', 'Authorization code is invalid, expired or already used.' );
	}
	if ( ! verifier_matches( $verifier, (string) $row['code_challenge'] ) ) {
		Storage\revoke_grant( $client['client_id'], (int) $row['user_id'] );
		return error( 'invalid_grant', 'PKCE verification failed.' );
	}
	$redirect_uri = (string) $request->get_param( 'redirect_uri' );
	if ( '' !== $redirect_uri && $redirect_uri !== $row['redirect_uri'] ) {
		return error( 'invalid_grant', 'redirect_uri does not match the authorization request.' );
	}
	$resource = (string) $request->get_param( 'resource' );
	if ( '' !== $resource && Servers\normalize( $resource ) !== Servers\normalize( (string) $row['resource'] ) ) {
		return error( 'invalid_target', 'The requested resource does not match the authorization request.' );
	}

	$tokens = Storage\issue_tokens( $client['client_id'], (int) $row['user_id'], (string) $row['scope'], (string) $row['resource'] );
	Storage\touch_client( $client['client_id'] );

	return respond(
		array(
			'access_token'  => $tokens['access_token'],
			'token_type'    => 'Bearer',
			'expires_in'    => $tokens['expires_in'],
			'refresh_token' => $tokens['refresh_token'],
			'scope'         => (string) $row['scope'],
		)
	);
}

/**
 * Rotate a refresh token.
 */
function refresh( WP_REST_Request $request, array $client ): WP_REST_Response {
	$raw = (string) $request->get_param( 'refresh_token' );
	if ( '' === $raw ) {
		return error( 'invalid_request', 'refresh_token is required.' );
	}
	$row = Storage\consume_refresh_token( $raw );
	if ( ! $row || ! hash_equals( (string) $row['client_id'], $client['client_id'] ) ) {
		return error( 'invalid_grant', 'Refresh token is invalid, expired or revoked.' );
	}
	$user = get_user_by( 'id', (int) $row['user_id'] );
	if ( ! $user || ! user_can( $user, \MCP_OAuth\authorize_capability() ) ) {
		return error( 'invalid_grant', 'The authorizing user no longer exists or may not use MCP.' );
	}
	$resource = (string) $request->get_param( 'resource' );
	if ( '' !== $resource && Servers\normalize( $resource ) !== Servers\normalize( (string) $row['resource'] ) ) {
		return error( 'invalid_target', 'The requested resource does not match the original grant.' );
	}

	$tokens = Storage\issue_tokens( $client['client_id'], (int) $row['user_id'], (string) $row['scope'], (string) $row['resource'] );
	Storage\touch_client( $client['client_id'] );

	return respond(
		array(
			'access_token'  => $tokens['access_token'],
			'token_type'    => 'Bearer',
			'expires_in'    => $tokens['expires_in'],
			'refresh_token' => $tokens['refresh_token'],
			'scope'         => (string) $row['scope'],
		)
	);
}

/**
 * Handle a revocation request. Always 200 for a well-formed request, per RFC 7009.
 */
function handle_revoke( WP_REST_Request $request ): WP_REST_Response {
	if ( ! Register\within_rate_limit( 'revoke', REQUESTS_PER_MINUTE, MINUTE_IN_SECONDS ) ) {
		return error( 'temporarily_unavailable', 'Too many requests.', 429 );
	}
	$token = (string) $request->get_param( 'token' );
	if ( '' === $token ) {
		return error( 'invalid_request', 'token is required.' );
	}
	Storage\revoke_token( $token, request_client_id( $request ) );
	return respond( array() );
}
