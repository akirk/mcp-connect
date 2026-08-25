<?php
/**
 * PKCE and token-endpoint request parsing.
 *
 * @package mcp-connect
 */

use MCP_OAuth\Token;
use PHPUnit\Framework\TestCase;

class Test_Pkce extends TestCase {
	protected function setUp(): void {
		wp_test_reset();
	}

	private function challenge( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	public function test_s256_verifier_matches_its_challenge(): void {
		$verifier = str_repeat( 'a', 43 );
		$this->assertTrue( Token\verifier_matches( $verifier, $this->challenge( $verifier ) ) );
	}

	public function test_rfc_7636_example_vector(): void {
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
		$this->assertTrue( Token\verifier_matches( $verifier, $challenge ) );
	}

	public function test_wrong_verifier_fails(): void {
		$verifier = str_repeat( 'a', 43 );
		$this->assertFalse( Token\verifier_matches( str_repeat( 'b', 43 ), $this->challenge( $verifier ) ) );
	}

	public function test_verifier_outside_rfc_length_or_alphabet_fails(): void {
		$short = str_repeat( 'a', 42 );
		$this->assertFalse( Token\verifier_matches( $short, $this->challenge( $short ) ) );
		$long = str_repeat( 'a', 129 );
		$this->assertFalse( Token\verifier_matches( $long, $this->challenge( $long ) ) );
		$bad = str_repeat( 'a', 42 ) . '!';
		$this->assertFalse( Token\verifier_matches( $bad, $this->challenge( $bad ) ) );
	}

	public function test_client_id_comes_from_body_or_basic_auth(): void {
		$body = new WP_REST_Request( 'POST', '/mcp-oauth/v1/token', array( 'client_id' => 'abc' ) );
		$this->assertSame( 'abc', Token\request_client_id( $body ) );

		$basic = new WP_REST_Request( 'POST', '/mcp-oauth/v1/token', array(), array( 'Authorization' => 'Basic ' . base64_encode( 'my%20client:' ) ) );
		$this->assertSame( 'my client', Token\request_client_id( $basic ) );

		$none = new WP_REST_Request( 'POST', '/mcp-oauth/v1/token' );
		$this->assertSame( '', Token\request_client_id( $none ) );

		$garbage = new WP_REST_Request( 'POST', '/mcp-oauth/v1/token', array(), array( 'Authorization' => 'Basic !!!' ) );
		$this->assertSame( '', Token\request_client_id( $garbage ) );
	}

	public function test_token_errors_carry_no_store_headers(): void {
		$response = Token\error( 'invalid_grant', 'nope' );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
		$this->assertSame( 'no-store', $response->headers['Cache-Control'] );
	}
}
