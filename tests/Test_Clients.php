<?php
/**
 * The client catalog on the Connect page.
 *
 * @package mcp-connect
 */

use MCP_OAuth\Clients;
use PHPUnit\Framework\TestCase;

class Test_Clients extends TestCase {
	protected function setUp(): void {
		wp_test_reset();
	}

	public function test_shell_quoting(): void {
		$this->assertSame( "'plain'", Clients\sh( 'plain' ) );
		$this->assertSame( "'it'\\''s'", Clients\sh( "it's" ) );
	}

	public function test_server_key_is_derived_from_the_site_name(): void {
		$GLOBALS['wp_test']['blogname'] = 'Demo Bakery & Café';
		$this->assertSame( 'demo-bakery-caf', Clients\server_key() );
		$GLOBALS['wp_test']['blogname'] = '';
		$this->assertSame( 'wordpress', Clients\server_key() );
	}

	public function test_claude_link_prefills_name_and_url(): void {
		$link = Clients\claude_connector_link( 'https://example.com/wp-json/mcp/x' );
		$this->assertStringStartsWith( 'https://claude.ai/customize/connectors?modal=add-custom-connector', $link );
		$this->assertStringContainsString( 'connectorName=Example%20Site', $link );
		$this->assertStringContainsString( 'connectorUrl=https%3A%2F%2Fexample.com%2Fwp-json%2Fmcp%2Fx', $link );
	}

	public function test_cursor_deeplink_carries_the_url(): void {
		$link = Clients\cursor_deeplink( 'https://example.com/wp-json/mcp/x' );
		$this->assertStringStartsWith( 'cursor://anysphere.cursor-deeplink/mcp/install?name=example-site&config=', $link );
		$config = json_decode( base64_decode( substr( $link, strpos( $link, 'config=' ) + 7 ) ), true );
		$this->assertSame( array( 'url' => 'https://example.com/wp-json/mcp/x' ), $config );
	}

	public function test_catalog_covers_the_major_clients_with_usable_content(): void {
		$catalog = Clients\catalog( 'https://example.com/wp-json/mcp/x' );
		foreach ( array( 'claude-ai', 'claude-desktop', 'claude-code', 'chatgpt', 'codex', 'cursor', 'vscode', 'other' ) as $key ) {
			$this->assertArrayHasKey( $key, $catalog );
			$this->assertNotEmpty( $catalog[ $key ]['name'] );
			$this->assertTrue(
				! empty( $catalog[ $key ]['link'] ) || ! empty( $catalog[ $key ]['command'] ) || ! empty( $catalog[ $key ]['snippet'] ) || ! empty( $catalog[ $key ]['steps'] ),
				"$key has something to copy or follow"
			);
		}
		$this->assertTrue( $catalog['claude-ai']['cloud'] );
		$this->assertStringContainsString( "claude mcp add --transport http --scope user 'example-site' 'https://example.com/wp-json/mcp/x'", $catalog['claude-code']['command'] );
		$this->assertSame( array( 'mcpServers' => array( 'example-site' => array( 'url' => 'https://example.com/wp-json/mcp/x' ) ) ), json_decode( $catalog['cursor']['snippet'], true ) );
		$this->assertSame( 'http', json_decode( $catalog['vscode']['snippet'], true )['servers']['example-site']['type'] );
	}

	public function test_cloud_reachability_heuristics(): void {
		$this->assertTrue( Clients\reachable_from_cloud() );
		foreach ( array( 'http://localhost', 'https://site.local', 'https://site.test', 'https://192.168.0.2', 'https://intranet' ) as $home ) {
			$GLOBALS['wp_test']['home_url'] = $home;
			$this->assertFalse( Clients\reachable_from_cloud(), $home );
		}
		$GLOBALS['wp_test']['home_url']    = 'https://example.com';
		$GLOBALS['wp_test']['environment'] = 'local';
		$this->assertFalse( Clients\reachable_from_cloud() );
	}
}
