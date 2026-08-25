<?php
/**
 * Bearer-token parsing and the WWW-Authenticate challenge.
 *
 * @package mcp-connect
 */

use MCP_OAuth\Middleware;
use PHPUnit\Framework\TestCase;
use WP\MCP\Core\McpServer;

class Test_Bearer extends TestCase {
	protected function setUp(): void {
		wp_test_reset();
	}

	public function test_bearer_token_is_extracted_case_insensitively(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'bearer   abc123 ';
		$this->assertTrue( Middleware\has_bearer_scheme() );
		$this->assertSame( 'abc123', Middleware\bearer_token() );
	}

	public function test_redirect_header_fallback_is_used(): void {
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer xyz';
		$this->assertSame( 'xyz', Middleware\bearer_token() );
	}

	public function test_other_schemes_are_not_bearer(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
		$this->assertFalse( Middleware\has_bearer_scheme() );
		$this->assertNull( Middleware\bearer_token() );
	}

	public function test_bearer_without_token_is_malformed(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer';
		$this->assertTrue( Middleware\has_bearer_scheme() );
		$this->assertNull( Middleware\bearer_token() );
	}

	public function test_existing_identity_is_never_replaced(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer whatever';
		$this->assertSame( 7, Middleware\resolve_bearer( 7 ) );
	}

	public function test_request_without_bearer_is_left_alone(): void {
		$this->assertNull( Middleware\resolve_bearer( null ) );
		$this->assertNull( Middleware\reject_invalid_bearer( null ) );
	}

	public function test_malformed_bearer_is_rejected_with_401(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer';
		$this->assertNull( Middleware\resolve_bearer( null ) );
		$error = Middleware\reject_invalid_bearer( null );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'rest_oauth_invalid_token', $error->get_error_code() );
		$this->assertSame( 401, $error->get_error_data()['status'] );
	}

	public function test_challenge_names_the_resource_metadata_and_scope(): void {
		$server    = new McpServer( 'srv', 'mcp', 'my-server', 'My Server' );
		$challenge = Middleware\challenge( $server );
		$this->assertSame( 'Bearer resource_metadata="https://example.com/.well-known/oauth-protected-resource/wp-json/mcp/my-server", scope="mcp"', $challenge );

		$invalid = Middleware\challenge( $server, 'invalid_token', 'It "expired"' );
		$this->assertStringContainsString( 'error="invalid_token"', $invalid );
		$this->assertStringContainsString( "error_description=\"It 'expired'\"", $invalid );
	}

	public function test_challenge_without_any_server_still_advertises_scope(): void {
		$this->assertSame( 'Bearer scope="mcp"', Middleware\challenge( null ) );
	}

	public function test_anonymous_request_to_mcp_route_is_challenged(): void {
		$GLOBALS['wp_test']['servers'] = array( 'srv' => new McpServer( 'srv', 'mcp', 'my-server', 'My Server' ) );
		$request                       = new WP_REST_Request( 'POST', '/mcp/my-server' );

		$result = Middleware\authorize_route( null, array(), $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_oauth_required', $result->get_error_code() );

		$converted = Middleware\attach_challenge( $result, array(), $request );
		$this->assertInstanceOf( WP_REST_Response::class, $converted );
		$this->assertSame( 401, $converted->get_status() );
		$this->assertSame( 'Bearer resource_metadata="https://example.com/.well-known/oauth-protected-resource/wp-json/mcp/my-server", scope="mcp"', $converted->headers['WWW-Authenticate'] );
	}

	public function test_errors_on_other_routes_get_no_challenge(): void {
		$GLOBALS['wp_test']['servers'] = array( 'srv' => new McpServer( 'srv', 'mcp', 'my-server', 'My Server' ) );
		$error                         = new WP_Error( 'rest_forbidden', 'no', array( 'status' => 401 ) );
		$this->assertSame( $error, Middleware\attach_challenge( $error, array(), new WP_REST_Request( 'GET', '/wp/v2/posts' ) ) );
		$ok = new WP_REST_Response( array() );
		$this->assertSame( $ok, Middleware\attach_challenge( $ok, array(), new WP_REST_Request( 'POST', '/mcp/my-server' ) ) );
	}

	public function test_anonymous_request_to_other_routes_is_not_touched(): void {
		$GLOBALS['wp_test']['servers'] = array( 'srv' => new McpServer( 'srv', 'mcp', 'my-server', 'My Server' ) );
		$request                       = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$this->assertNull( Middleware\authorize_route( null, array(), $request ) );
	}

	public function test_options_preflight_is_not_challenged(): void {
		$GLOBALS['wp_test']['servers'] = array( 'srv' => new McpServer( 'srv', 'mcp', 'my-server', 'My Server' ) );
		$request                       = new WP_REST_Request( 'OPTIONS', '/mcp/my-server' );
		$this->assertNull( Middleware\authorize_route( null, array(), $request ) );
	}
}
