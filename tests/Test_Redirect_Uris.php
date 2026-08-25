<?php
/**
 * Redirect URI rules for registration and authorization.
 *
 * @package mcp-connect
 */

use MCP_OAuth\Authorize;
use MCP_OAuth\Register;
use PHPUnit\Framework\TestCase;

class Test_Redirect_Uris extends TestCase {
	protected function setUp(): void {
		wp_test_reset();
	}

	public function test_https_to_public_host_is_allowed(): void {
		$this->assertTrue( Register\is_allowed_redirect_uri( 'https://claude.ai/api/mcp/auth_callback' ) );
		$this->assertTrue( Register\is_allowed_redirect_uri( 'https://chatgpt.com/connector_platform_oauth_redirect' ) );
	}

	public function test_fragment_is_rejected(): void {
		$this->assertFalse( Register\is_allowed_redirect_uri( 'https://claude.ai/cb#frag' ) );
	}

	public function test_plain_http_is_only_allowed_on_loopback(): void {
		$this->assertTrue( Register\is_allowed_redirect_uri( 'http://127.0.0.1:53421/callback' ) );
		$this->assertTrue( Register\is_allowed_redirect_uri( 'http://localhost/callback' ) );
		$this->assertTrue( Register\is_allowed_redirect_uri( 'http://[::1]:8080/cb' ) );
		$this->assertFalse( Register\is_allowed_redirect_uri( 'http://evil.example/cb' ) );
		$this->assertFalse( Register\is_allowed_redirect_uri( 'http://192.168.1.5/cb' ) );
	}

	public function test_native_app_schemes_are_allowed_and_others_are_not(): void {
		$this->assertTrue( Register\is_allowed_redirect_uri( 'claude://oauth/callback' ) );
		$this->assertTrue( Register\is_allowed_redirect_uri( 'cursor://anysphere.cursor-mcp/oauth/callback' ) );
		$this->assertTrue( Register\is_allowed_redirect_uri( 'vscode://redhat.mcp/callback' ) );
		$this->assertFalse( Register\is_allowed_redirect_uri( 'javascript:alert(1)' ) );
		$this->assertFalse( Register\is_allowed_redirect_uri( 'ftp://example.com/cb' ) );
		$this->assertFalse( Register\is_allowed_redirect_uri( 'not a url' ) );
	}

	public function test_custom_scheme_list_is_filterable(): void {
		add_filter(
			'mcp_oauth_redirect_uri_schemes',
			static function ( array $schemes ): array {
				$schemes[] = 'myapp';
				return $schemes;
			}
		);
		$this->assertTrue( Register\is_allowed_redirect_uri( 'myapp://callback' ) );
	}

	public function test_private_and_local_https_hosts_need_a_local_environment(): void {
		$this->assertFalse( Register\is_allowed_redirect_uri( 'https://10.0.0.1/cb' ) );
		$this->assertFalse( Register\is_allowed_redirect_uri( 'https://app.local/cb' ) );
		$this->assertFalse( Register\is_allowed_redirect_uri( 'https://localhost/cb' ) );

		$GLOBALS['wp_test']['environment'] = 'local';
		$this->assertTrue( Register\is_allowed_redirect_uri( 'https://10.0.0.1/cb' ) );
		$this->assertTrue( Register\is_allowed_redirect_uri( 'https://app.local/cb' ) );
	}

	public function test_registered_uri_must_match_exactly_except_loopback_port(): void {
		$registered = array( 'https://claude.ai/api/mcp/auth_callback', 'http://127.0.0.1:1234/cb' );

		$this->assertTrue( Authorize\redirect_uri_registered( 'https://claude.ai/api/mcp/auth_callback', $registered ) );
		$this->assertFalse( Authorize\redirect_uri_registered( 'https://claude.ai/api/mcp/auth_callback/../x', $registered ) );
		$this->assertFalse( Authorize\redirect_uri_registered( 'https://claude.ai/other', $registered ) );
		$this->assertFalse( Authorize\redirect_uri_registered( 'https://attacker.example/api/mcp/auth_callback', $registered ) );

		// RFC 8252 §7.3: a native client may bind a different loopback port each run.
		$this->assertTrue( Authorize\redirect_uri_registered( 'http://127.0.0.1:60000/cb', $registered ) );
		$this->assertFalse( Authorize\redirect_uri_registered( 'http://127.0.0.1:60000/other', $registered ) );
		$this->assertFalse( Authorize\redirect_uri_registered( 'https://127.0.0.1:60000/cb', $registered ) );
	}

	public function test_destination_label_shows_scheme_and_host_only(): void {
		$this->assertSame( 'https://claude.ai', Authorize\destination_label( 'https://claude.ai/api/mcp/auth_callback?x=1' ) );
		$this->assertSame( 'claude://oauth', Authorize\destination_label( 'claude://oauth/callback' ) );
		$this->assertSame( 'not a url', Authorize\destination_label( 'not a url' ) );
	}
}
