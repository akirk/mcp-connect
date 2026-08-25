<?php
/**
 * Site Health integration.
 *
 * A status test that probes, from the server itself, whether the discovery
 * documents are served and the MCP endpoint challenges anonymous requests —
 * the two things a client needs before it can start the sign-in. It runs
 * asynchronously through a REST route like core's own loopback test, so the
 * Site Health page does not block on the requests. The Info tab lists the
 * endpoints and settings.
 *
 * @package mcp-connect
 */

namespace MCP_OAuth\Health;

use MCP_OAuth\Abilities;
use MCP_OAuth\Discovery;
use MCP_OAuth\Servers;
use MCP_OAuth\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Hook into Site Health.
 */
function register(): void {
	add_filter( 'site_status_tests', __NAMESPACE__ . '\add_test' );
	add_filter( 'debug_information', __NAMESPACE__ . '\add_info' );
	add_action( 'rest_api_init', __NAMESPACE__ . '\register_routes' );
}

/**
 * Register the async test.
 */
function add_test( array $tests ): array {
	$tests['async']['mcp_oauth'] = array(
		'label'             => __( 'MCP Connect endpoints', 'mcp-oauth' ),
		'test'              => rest_url( \MCP_OAuth\REST_NAMESPACE . '/health' ),
		'has_rest'          => true,
		'async_direct_test' => __NAMESPACE__ . '\test',
	);
	return $tests;
}

/**
 * The REST route the async test calls.
 */
function register_routes(): void {
	register_rest_route(
		\MCP_OAuth\REST_NAMESPACE,
		'/health',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\test',
			'permission_callback' => static function (): bool {
				return current_user_can( 'view_site_health_checks' );
			},
		)
	);
}

/**
 * Probe one URL and check the JSON field that proves the document is ours.
 *
 * @return array{ok:bool,detail:string}
 */
function probe_document( string $url, string $field ): array {
	$response = wp_remote_get( $url, request_args() );
	if ( is_wp_error( $response ) ) {
		return array(
			'ok'     => false,
			'detail' => $response->get_error_message(),
		);
	}
	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 200 === $code && is_array( $body ) && ! empty( $body[ $field ] ) ) {
		return array(
			'ok'     => true,
			'detail' => '',
		);
	}
	return array(
		'ok'     => false,
		/* translators: %d: HTTP status code */
		'detail' => sprintf( __( 'HTTP %d, or the response is not this plugin’s metadata document.', 'mcp-oauth' ), $code ),
	);
}

/**
 * Probe the MCP endpoint for the anonymous 401 challenge.
 *
 * @return array{ok:bool,detail:string}
 */
function probe_challenge( string $url ): array {
	$response = wp_remote_post(
		$url,
		request_args(
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => '{"jsonrpc":"2.0","id":1,"method":"ping"}',
			)
		)
	);
	if ( is_wp_error( $response ) ) {
		return array(
			'ok'     => false,
			'detail' => $response->get_error_message(),
		);
	}
	$code      = wp_remote_retrieve_response_code( $response );
	$challenge = (string) wp_remote_retrieve_header( $response, 'www-authenticate' );
	if ( 401 === $code && false !== stripos( $challenge, 'resource_metadata=' ) ) {
		return array(
			'ok'     => true,
			'detail' => '',
		);
	}
	return array(
		'ok'     => false,
		/* translators: %d: HTTP status code */
		'detail' => sprintf( __( 'HTTP %d without the expected WWW-Authenticate challenge.', 'mcp-oauth' ), $code ),
	);
}

/**
 * Loopback request arguments.
 */
function request_args( array $extra = array() ): array {
	return $extra + array(
		'timeout'   => 10,
		'sslverify' => 'local' !== wp_get_environment_type(),
		'headers'   => array(),
	);
}

/**
 * Build the Site Health result.
 *
 * @return array Site Health test result.
 */
function test(): array {
	$result = array(
		'label'       => __( 'AI clients can discover and sign in to this site’s MCP server', 'mcp-oauth' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'MCP', 'mcp-oauth' ),
			'color' => 'blue',
		),
		'description' => '',
		'actions'     => sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( \MCP_OAuth\Admin\page_url() ),
			esc_html__( 'Open MCP Connect', 'mcp-oauth' )
		),
		'test'        => 'mcp_oauth',
	);

	if ( ! \MCP_OAuth\adapter_available() ) {
		$result['status']      = 'critical';
		$result['label']       = __( 'The MCP Adapter plugin is not active', 'mcp-oauth' );
		$result['description'] = '<p>' . esc_html__( 'MCP Connect needs the MCP Adapter plugin, which provides the MCP server itself.', 'mcp-oauth' ) . '</p>';
		return $result;
	}
	if ( ! \MCP_OAuth\transport_allowed() ) {
		$result['status']      = 'critical';
		$result['label']       = __( 'The MCP Connect endpoints are disabled because the site is not served over HTTPS', 'mcp-oauth' );
		$result['description'] = '<p>' . esc_html__( 'Sign-in tokens would travel unencrypted. Switch the site to HTTPS to let AI clients connect.', 'mcp-oauth' ) . '</p>';
		return $result;
	}
	$server = Servers\primary();
	if ( ! $server ) {
		$result['status']      = 'critical';
		$result['label']       = __( 'No MCP server is registered', 'mcp-oauth' );
		$result['description'] = '<p>' . esc_html__( 'The MCP Adapter’s default server is missing — check the mcp_adapter_create_default_server filter.', 'mcp-oauth' ) . '</p>';
		return $result;
	}

	$probes = array(
		array( __( 'Protected-resource metadata', 'mcp-oauth' ), Discovery\protected_resource_metadata_url( $server ), probe_document( Discovery\protected_resource_metadata_url( $server ), 'resource' ) ),
		array( __( 'Authorization-server metadata', 'mcp-oauth' ), Discovery\issuer() . Discovery\AUTHORIZATION_SERVER, probe_document( Discovery\issuer() . Discovery\AUTHORIZATION_SERVER, 'issuer' ) ),
		array( __( 'Sign-in challenge on the MCP endpoint', 'mcp-oauth' ), Servers\resource( $server ), probe_challenge( Servers\resource( $server ) ) ),
	);

	$failed = array();
	$list   = '<ul>';
	foreach ( $probes as list( $label, $url, $probe ) ) {
		$list .= '<li>' . ( $probe['ok'] ? '✔ ' : '✘ ' ) . esc_html( $label ) . ' — <code>' . esc_html( $url ) . '</code>' . ( $probe['ok'] ? '' : '<br>' . esc_html( $probe['detail'] ) ) . '</li>';
		if ( ! $probe['ok'] ) {
			$failed[] = $label;
		}
	}
	$list .= '</ul>';

	if ( $failed ) {
		$result['status']      = 'critical';
		$result['label']       = __( 'AI clients cannot discover how to sign in to this site’s MCP server', 'mcp-oauth' );
		$result['description'] = '<p>' . esc_html__( 'These requests were made from the server to its own public URL. A page cache, a security plugin or the web server may be answering before WordPress does, or stripping headers.', 'mcp-oauth' ) . '</p>' . $list;
	} else {
		$result['description'] = '<p>' . esc_html__( 'The discovery documents are served and anonymous requests to the MCP endpoint receive the sign-in challenge.', 'mcp-oauth' ) . '</p>' . $list;
	}
	return $result;
}

/**
 * The Info tab section.
 */
function add_info( array $info ): array {
	$server  = \MCP_OAuth\adapter_available() ? Servers\primary() : null;
	$servers = \MCP_OAuth\adapter_available() ? Servers\all() : array();
	$fields  = array(
		'version'     => array(
			'label' => __( 'Version', 'mcp-oauth' ),
			'value' => MCP_OAUTH_VERSION,
		),
		'transport'   => array(
			'label' => __( 'OAuth endpoints enabled', 'mcp-oauth' ),
			'value' => \MCP_OAuth\transport_allowed() ? __( 'Yes', 'mcp-oauth' ) : __( 'No (site is not HTTPS)', 'mcp-oauth' ),
		),
		'endpoint'    => array(
			'label' => __( 'MCP endpoint', 'mcp-oauth' ),
			'value' => $server ? Servers\resource( $server ) : __( 'none', 'mcp-oauth' ),
		),
		'servers'     => array(
			'label' => __( 'Registered MCP servers', 'mcp-oauth' ),
			'value' => $servers ? implode( ', ', array_keys( $servers ) ) : __( 'none', 'mcp-oauth' ),
		),
		'issuer'      => array(
			'label' => __( 'Issuer', 'mcp-oauth' ),
			'value' => Discovery\issuer(),
		),
		'authorize'   => array(
			'label' => __( 'Authorization endpoint', 'mcp-oauth' ),
			'value' => \MCP_OAuth\Authorize\endpoint_url(),
		),
		'hidden'      => array(
			'label' => __( 'Abilities hidden by an administrator', 'mcp-oauth' ),
			'value' => Abilities\hidden() ? implode( ', ', Abilities\hidden() ) : __( 'none', 'mcp-oauth' ),
		),
		'visible'     => array(
			'label' => __( 'Abilities visible to clients', 'mcp-oauth' ),
			'value' => (string) count( array_filter( array_column( Abilities\report(), 'visible' ) ) ) . ' / ' . count( Abilities\report() ),
		),
		'connections' => array(
			'label' => __( 'Active connections', 'mcp-oauth' ),
			'value' => (string) ( Storage\installed() ? count( Storage\list_connections() ) : 0 ),
		),
	);
	$info['mcp-oauth'] = array(
		'label'  => __( 'MCP Connect', 'mcp-oauth' ),
		'fields' => $fields,
	);
	return $info;
}
