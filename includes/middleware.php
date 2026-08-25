<?php
/**
 * Bearer-token authentication for MCP routes.
 *
 * - A valid access token establishes the authorizing user, but only on the MCP
 *   route the token was issued for (its resource).
 * - A request to an MCP route without any credentials is answered with 401 and a
 *   `WWW-Authenticate` challenge pointing at the protected-resource metadata,
 *   which is how an MCP client discovers that (and where) it must sign in.
 * - An invalid token is rejected outright rather than silently treated as anonymous.
 *
 * Cookie and Application Password authentication keep working untouched.
 *
 * @package mcp-connect
 */

namespace MCP_OAuth\Middleware;

use MCP_OAuth\Discovery;
use MCP_OAuth\Servers;
use MCP_OAuth\Storage;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Hook everything.
 */
function register(): void {
	add_filter( 'determine_current_user', __NAMESPACE__ . '\resolve_bearer', 30 );
	add_filter( 'rest_authentication_errors', __NAMESPACE__ . '\reject_invalid_bearer', 5 );
	add_filter( 'rest_request_before_callbacks', __NAMESPACE__ . '\authorize_route', 5, 3 );
	add_filter( 'rest_request_after_callbacks', __NAMESPACE__ . '\attach_challenge', 5, 3 );
}

/**
 * Request-local state: the token that authenticated this request, or the error
 * an invalid one produced.
 *
 * @return array{token:?array,error:?WP_Error}
 */
function &state(): array {
	static $state = array(
		'token' => null,
		'error' => null,
	);
	return $state;
}

/**
 * The Authorization header, wherever the server put it.
 */
function authorization_header(): string {
	foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) ) {
			return trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
		}
	}
	if ( function_exists( 'getallheaders' ) ) {
		foreach ( getallheaders() as $name => $value ) {
			if ( 'authorization' === strtolower( $name ) ) {
				return trim( (string) $value );
			}
		}
	}
	return '';
}

/**
 * The bearer token in the request, or null when the request carries none.
 */
function bearer_token(): ?string {
	$header = authorization_header();
	if ( ! preg_match( '/^Bearer\s+(\S+)\s*$/i', $header, $m ) ) {
		return null;
	}
	return $m[1];
}

/**
 * Whether the request presents a Bearer credential at all (valid or not).
 */
function has_bearer_scheme(): bool {
	return 0 === stripos( authorization_header(), 'Bearer' );
}

/**
 * Establish the token's user when nothing else authenticated the request.
 *
 * @param int|false|null $user_id Identity established so far.
 * @return int|false|null
 */
function resolve_bearer( $user_id ) {
	$state = &state();
	if ( ! empty( $user_id ) ) {
		return $user_id;
	}
	if ( ! has_bearer_scheme() ) {
		return $user_id;
	}
	$raw = bearer_token();
	if ( null === $raw || ! Storage\installed() ) {
		$state['error'] = new WP_Error( 'rest_oauth_invalid_token', __( 'Malformed bearer token.', 'mcp-oauth' ), array( 'status' => 401 ) );
		return $user_id;
	}
	$token = Storage\find_token( $raw, 'access' );
	if ( ! $token ) {
		$state['error'] = new WP_Error( 'rest_oauth_invalid_token', __( 'The access token is invalid, expired or revoked.', 'mcp-oauth' ), array( 'status' => 401 ) );
		return $user_id;
	}
	$user = get_user_by( 'id', (int) $token['user_id'] );
	if ( ! $user || ! user_can( $user, \MCP_OAuth\authorize_capability() ) ) {
		$state['error'] = new WP_Error( 'rest_oauth_invalid_token', __( 'The authorizing user may no longer use MCP.', 'mcp-oauth' ), array( 'status' => 401 ) );
		return $user_id;
	}
	Storage\touch_token( $token );
	$state['token'] = $token;
	return (int) $token['user_id'];
}

/**
 * Turn an invalid bearer token into a 401 before any route runs.
 *
 * @param WP_Error|true|null $result Result so far.
 * @return WP_Error|true|null
 */
function reject_invalid_bearer( $result ) {
	$state = &state();
	if ( null === $state['error'] ) {
		return $result;
	}
	$server = Servers\for_route( rest_route_of_request() );
	if ( ! headers_sent() ) {
		header( 'WWW-Authenticate: ' . challenge( $server, 'invalid_token', $state['error']->get_error_message() ) );
	}
	return $state['error'];
}

/**
 * The REST route being served, before dispatch has matched it.
 */
function rest_route_of_request(): string {
	if ( ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
		return (string) $GLOBALS['wp']->query_vars['rest_route'];
	}
	$route = isset( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' !== $route ) {
		return $route;
	}
	$path   = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$prefix = (string) wp_parse_url( rest_url(), PHP_URL_PATH );
	return 0 === strpos( $path, $prefix ) ? '/' . substr( $path, strlen( $prefix ) ) : '';
}

/**
 * Enforce the OAuth boundary on the matched route.
 *
 * @param mixed           $result  Response to send instead of dispatching, if any.
 * @param array           $handler Matched handler.
 * @param WP_REST_Request $request Request.
 * @return mixed
 */
function authorize_route( $result, $handler, $request ) {
	$state  = &state();
	$server = Servers\for_route( $request->get_route() );

	if ( null !== $state['token'] ) {
		// An OAuth identity is only good for the MCP server the token was issued for.
		if ( ! $server ) {
			return new WP_Error( 'rest_oauth_route_forbidden', __( 'This access token is only accepted on its MCP endpoint.', 'mcp-oauth' ), array( 'status' => 403 ) );
		}
		if ( Servers\normalize( (string) $state['token']['resource'] ) !== Servers\normalize( Servers\resource( $server ) ) ) {
			return new WP_Error( 'rest_oauth_wrong_resource', __( 'This access token was issued for a different MCP server.', 'mcp-oauth' ), array( 'status' => 403 ) );
		}
		return $result;
	}

	if ( $server && null === $result && 0 === get_current_user_id() && ! has_bearer_scheme() && 'OPTIONS' !== $request->get_method() ) {
		return new WP_Error( 'rest_oauth_required', __( 'Authentication required.', 'mcp-oauth' ), array( 'status' => 401 ) );
	}
	return $result;
}

/**
 * Add the WWW-Authenticate challenge to 401/403 responses on MCP routes.
 *
 * Done on the response object (not with header()) so internal dispatches such
 * as batch requests never leak a header into the outer response.
 *
 * @param mixed           $response Response or error.
 * @param array           $handler  Matched handler.
 * @param WP_REST_Request $request  Request.
 * @return mixed
 */
function attach_challenge( $response, $handler, $request ) {
	if ( ! is_wp_error( $response ) ) {
		return $response;
	}
	$server = Servers\for_route( $request->get_route() );
	if ( ! $server ) {
		return $response;
	}
	$data   = $response->get_error_data();
	$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	$code   = (string) $response->get_error_code();

	if ( 401 === $status ) {
		$value = challenge( $server, 'rest_oauth_required' === $code || 'rest_forbidden' === $code ? '' : 'invalid_token' );
	} elseif ( 403 === $status && 0 === strpos( $code, 'rest_oauth_' ) ) {
		$value = challenge( $server, 'insufficient_scope' );
	} else {
		return $response;
	}
	$converted = rest_convert_error_to_response( $response );
	$converted->header( 'WWW-Authenticate', $value );
	return $converted;
}

/**
 * RFC 6750 / RFC 9728 challenge value.
 *
 * @param \WP\MCP\Core\McpServer|null $server      The resource, or null for a generic challenge.
 * @param string                      $error       Error code, or '' when no credentials were sent (RFC 6750 §3.1).
 * @param string                      $description Error description.
 */
function challenge( $server, string $error = '', string $description = '' ): string {
	$server = $server ? $server : Servers\primary();
	$parts  = array();
	if ( $server ) {
		$parts[] = 'resource_metadata="' . Discovery\protected_resource_metadata_url( $server ) . '"';
	}
	if ( '' !== $error ) {
		$parts[] = 'error="' . $error . '"';
	}
	if ( '' !== $description ) {
		$parts[] = 'error_description="' . str_replace( '"', "'", $description ) . '"';
	}
	$parts[] = 'scope="' . \MCP_OAuth\SCOPE . '"';
	return 'Bearer ' . implode( ', ', $parts );
}
