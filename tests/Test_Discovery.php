<?php
/**
 * Discovery documents and the URLs they are served on.
 *
 * @package mcp-connect
 */

use MCP_OAuth\Discovery;
use MCP_OAuth\Servers;
use PHPUnit\Framework\TestCase;
use WP\MCP\Core\McpServer;

class Test_Discovery extends TestCase {
	protected function setUp(): void {
		wp_test_reset();
		$GLOBALS['wp_test']['servers'] = array(
			'mcp-adapter-default-server' => new McpServer( 'mcp-adapter-default-server', 'mcp', 'mcp-adapter-default-server', 'Default' ),
			'custom'                     => new McpServer( 'custom', 'acme/v1', 'assistant', 'Acme Assistant' ),
		);
	}

	public function test_issuer_and_home_path_for_root_install(): void {
		$this->assertSame( 'https://example.com', Discovery\issuer() );
		$this->assertSame( '', Discovery\home_path() );
	}

	public function test_home_path_for_subdirectory_install(): void {
		$GLOBALS['wp_test']['home_url'] = 'https://example.com/blog/';
		$this->assertSame( '/blog', Discovery\home_path() );
		$this->assertSame( 'https://example.com/blog', Discovery\issuer() );
	}

	public function test_protected_resource_metadata_url_uses_the_insert_form(): void {
		$server = Servers\primary();
		$this->assertSame(
			'https://example.com/.well-known/oauth-protected-resource/wp-json/mcp/mcp-adapter-default-server',
			Discovery\protected_resource_metadata_url( $server )
		);
	}

	public function test_plain_permalinks_fall_back_to_the_append_form(): void {
		$GLOBALS['wp_test']['permalinks'] = false;
		$server                           = Servers\primary();
		$this->assertSame( 'https://example.com/.well-known/oauth-protected-resource', Discovery\protected_resource_metadata_url( $server ) );
	}

	public function test_protected_resource_document(): void {
		$doc = Discovery\protected_resource_document( $GLOBALS['wp_test']['servers']['custom'] );
		$this->assertSame( 'https://example.com/wp-json/acme/v1/assistant', $doc['resource'] );
		$this->assertSame( 'Acme Assistant', $doc['resource_name'] );
		$this->assertSame( array( 'https://example.com' ), $doc['authorization_servers'] );
		$this->assertSame( array( 'mcp' ), $doc['scopes_supported'] );
	}

	public function test_authorization_server_document_points_at_login_and_rest_endpoints(): void {
		$doc = Discovery\authorization_server_document();
		$this->assertSame( 'https://example.com', $doc['issuer'] );
		$this->assertSame( 'https://example.com/wp-login.php?action=mcp-oauth-authorize', $doc['authorization_endpoint'] );
		$this->assertSame( 'https://example.com/wp-json/mcp-oauth/v1/token', $doc['token_endpoint'] );
		$this->assertSame( 'https://example.com/wp-json/mcp-oauth/v1/register', $doc['registration_endpoint'] );
		$this->assertSame( 'https://example.com/wp-json/mcp-oauth/v1/revoke', $doc['revocation_endpoint'] );
		$this->assertSame( array( 'S256' ), $doc['code_challenge_methods_supported'] );
		$this->assertSame( array( 'none' ), $doc['token_endpoint_auth_methods_supported'] );
		$this->assertSame( array( 'authorization_code', 'refresh_token' ), $doc['grant_types_supported'] );
	}

	public function test_servers_are_resolved_from_routes_resources_and_paths(): void {
		$this->assertSame( 'custom', Servers\for_route( '/acme/v1/assistant' )->get_server_id() );
		$this->assertSame( 'custom', Servers\for_route( '/acme/v1/assistant/sub' )->get_server_id() );
		$this->assertNull( Servers\for_route( '/acme/v1/assistant-other' ) );
		$this->assertNull( Servers\for_route( '/wp/v2/posts' ) );

		$this->assertSame( 'custom', Servers\for_resource( 'https://example.com/wp-json/acme/v1/assistant/' )->get_server_id() );
		$this->assertNull( Servers\for_resource( 'https://evil.example/wp-json/acme/v1/assistant' ) );
		$this->assertNull( Servers\for_resource( '' ) );

		$this->assertSame( 'mcp-adapter-default-server', Servers\for_url_path( '/wp-json/mcp/mcp-adapter-default-server' )->get_server_id() );
		$this->assertNull( Servers\for_url_path( '/wp-json/mcp/unknown' ) );
	}

	public function test_primary_prefers_the_default_server_then_the_first(): void {
		$this->assertSame( 'mcp-adapter-default-server', Servers\primary()->get_server_id() );
		unset( $GLOBALS['wp_test']['servers']['mcp-adapter-default-server'] );
		$this->assertSame( 'custom', Servers\primary()->get_server_id() );
		$GLOBALS['wp_test']['servers'] = array();
		$this->assertNull( Servers\primary() );
	}
}
