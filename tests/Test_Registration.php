<?php
/**
 * Dynamic client registration request validation (everything before the database).
 *
 * @package mcp-connect
 */

use MCP_OAuth\Register;
use PHPUnit\Framework\TestCase;

class Test_Registration extends TestCase {
	protected function setUp(): void {
		wp_test_reset();
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	private function request( $body ): WP_REST_Request {
		return new WP_REST_Request( 'POST', '/mcp-oauth/v1/register', $body );
	}

	public function test_non_object_body_is_rejected(): void {
		$response = Register\handle( $this->request( 'nope' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_client_metadata', $response->get_data()['error'] );
	}

	public function test_confidential_clients_are_refused(): void {
		$response = Register\handle( $this->request( array( 'redirect_uris' => array( 'https://claude.ai/cb' ), 'token_endpoint_auth_method' => 'client_secret_post' ) ) );
		$this->assertSame( 'invalid_client_metadata', $response->get_data()['error'] );
	}

	public function test_unsupported_grants_are_refused(): void {
		$response = Register\handle( $this->request( array( 'redirect_uris' => array( 'https://claude.ai/cb' ), 'grant_types' => array( 'client_credentials' ) ) ) );
		$this->assertSame( 'invalid_client_metadata', $response->get_data()['error'] );
	}

	public function test_redirect_uris_are_required_and_validated(): void {
		$this->assertSame( 'invalid_redirect_uri', Register\handle( $this->request( array( 'client_name' => 'x' ) ) )->get_data()['error'] );
		$this->assertSame( 'invalid_redirect_uri', Register\handle( $this->request( array( 'redirect_uris' => array() ) ) )->get_data()['error'] );
		$this->assertSame( 'invalid_redirect_uri', Register\handle( $this->request( array( 'redirect_uris' => array( 'http://evil.example/cb' ) ) ) )->get_data()['error'] );
		$this->assertSame( 'invalid_redirect_uri', Register\handle( $this->request( array( 'redirect_uris' => array_fill( 0, 11, 'https://claude.ai/cb' ) ) ) )->get_data()['error'] );
	}

	public function test_rate_limit_counts_per_address(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( Register\within_rate_limit( 'unit', 3, 60 ) );
		}
		$this->assertFalse( Register\within_rate_limit( 'unit', 3, 60 ) );
		$this->assertTrue( Register\within_rate_limit( 'other-bucket', 3, 60 ) );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$this->assertTrue( Register\within_rate_limit( 'unit', 3, 60 ) );
	}

	public function test_limits_are_filterable_and_sane(): void {
		$this->assertSame( array( 'per_hour' => 10, 'max_clients' => 20 ), Register\limits() );
		add_filter( 'mcp_oauth_registration_limits', static function () { return array( 'per_hour' => 0, 'max_clients' => '7' ); } );
		$this->assertSame( array( 'per_hour' => 1, 'max_clients' => 7 ), Register\limits() );
	}

	public function test_unknown_address_is_not_rate_limited(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( Register\within_rate_limit( 'unit', 1, 60 ) );
		}
	}
}
