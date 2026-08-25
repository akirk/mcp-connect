<?php
/**
 * The login-page authorization endpoint: request repair and return URLs.
 *
 * @package mcp-connect
 */

use MCP_OAuth\Authorize;
use PHPUnit\Framework\TestCase;

class Test_Authorize_Request extends TestCase {
	protected function setUp(): void {
		wp_test_reset();
	}

	public function test_endpoint_is_a_login_action(): void {
		$this->assertSame( 'https://example.com/wp-login.php?action=mcp-oauth-authorize', Authorize\endpoint_url() );
	}

	public function test_folded_query_is_unfolded_on_the_login_page(): void {
		$GLOBALS['pagenow']      = 'wp-login.php';
		$_GET['action']          = 'mcp-oauth-authorize?response_type=code&client_id=abc&redirect_uri=https%3A%2F%2Fclaude.ai%2Fcb';
		$_REQUEST['action']      = $_GET['action'];
		$_SERVER['QUERY_STRING'] = 'action=mcp-oauth-authorize?response_type=code&client_id=abc';
		$_SERVER['REQUEST_URI']  = '/wp-login.php?' . $_SERVER['QUERY_STRING'];

		Authorize\repair_folded_request();

		$this->assertSame( 'mcp-oauth-authorize', $_GET['action'] );
		$this->assertSame( 'mcp-oauth-authorize', $_REQUEST['action'] );
		$this->assertSame( 'code', $_GET['response_type'] );
		$this->assertSame( 'abc', $_GET['client_id'] );
		$this->assertSame( 'https://claude.ai/cb', $_GET['redirect_uri'] );
		$this->assertSame( 'action=mcp-oauth-authorize&response_type=code&client_id=abc', $_SERVER['QUERY_STRING'] );
	}

	public function test_folded_query_never_overrides_explicit_parameters(): void {
		$GLOBALS['pagenow'] = 'wp-login.php';
		$_GET['action']     = 'mcp-oauth-authorize?client_id=folded';
		$_REQUEST['action'] = $_GET['action'];
		$_GET['client_id']  = 'explicit';

		Authorize\repair_folded_request();

		$this->assertSame( 'explicit', $_GET['client_id'] );
	}

	public function test_repair_ignores_other_pages_and_actions(): void {
		$GLOBALS['pagenow'] = 'index.php';
		$_GET['action']     = 'mcp-oauth-authorize?client_id=x';
		$_REQUEST['action'] = $_GET['action'];
		Authorize\repair_folded_request();
		$this->assertSame( 'mcp-oauth-authorize?client_id=x', $_GET['action'] );

		$GLOBALS['pagenow'] = 'wp-login.php';
		$_GET['action']     = 'lostpassword?x=1';
		$_REQUEST['action'] = $_GET['action'];
		Authorize\repair_folded_request();
		$this->assertSame( 'lostpassword?x=1', $_GET['action'] );
	}

	public function test_request_url_preserves_encoded_parameters_for_the_login_redirect(): void {
		$_GET = array(
			'action'        => 'mcp-oauth-authorize',
			'client_id'     => 'abc',
			'redirect_uri'  => 'https://claude.ai/api/mcp/auth_callback',
			'state'         => 'a b&c',
			'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
		);

		$url = Authorize\request_url();
		$this->assertStringStartsWith( 'https://example.com/wp-login.php?', $url );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( $_GET, $query, 'Every parameter survives the round trip unchanged.' );
		$this->assertStringContainsString( 'redirect_uri=https%3A%2F%2Fclaude.ai%2Fapi%2Fmcp%2Fauth_callback', $url );
	}

	public function test_request_url_drops_non_string_and_odd_keys(): void {
		$_GET = array(
			'action'  => 'mcp-oauth-authorize',
			'arr'     => array( 'x' ),
			'we ird!' => 'v',
		);
		$this->assertSame( 'https://example.com/wp-login.php?action=mcp-oauth-authorize&weird=v', Authorize\request_url() );
	}

	public function test_param_reads_sanitized_query_values(): void {
		$_GET['state'] = " <b>st</b>ate\n";
		$this->assertSame( 'state', Authorize\param( 'state' ) );
		$this->assertSame( '', Authorize\param( 'missing' ) );
		$_GET['arr'] = array( 'x' );
		$this->assertSame( '', Authorize\param( 'arr' ) );
	}
}
