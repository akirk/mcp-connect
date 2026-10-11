<?php
/** @package mcp-connect */
use MCP_OAuth\Abilities;
use MCP_OAuth\WebMCP\Ability_Bridge;
use MCP_OAuth\WebMCP\Settings;
use MCP_OAuth\WebMCP\Secure_Context;
use PHPUnit\Framework\TestCase;

class Test_WebMCP extends TestCase {
	protected function setUp(): void {
		wp_test_reset();
	}

	public function test_preserves_legacy_visibility_and_allowlist(): void {
		update_option( 'wmcp_exposed_tools', array( 'demo/allowed' ) );
		$settings = new Settings();
		$settings->migrate_exposed_tools( array( 'demo/allowed', 'demo/hidden' ) );
		$this->assertSame( 'public', $settings->get_visibility_override( 'demo/allowed' ) );
		$this->assertSame( 'private', $settings->get_visibility_override( 'demo/hidden' ) );
		$settings->set_visibility_override( 'demo/allowed', 'authenticated' );
		$settings->migrate_exposed_tools( array( 'demo/allowed' ) );
		$this->assertSame( 'authenticated', $settings->get_visibility_override( 'demo/allowed' ) );
	}

	public function test_legacy_allowlist_hides_abilities_before_admin_migration(): void {
		Abilities\register();
		update_option( 'wmcp_exposed_tools', array( 'demo/allowed' ) );
		wp_test_register_ability( 'demo/allowed' );
		wp_test_register_ability( 'demo/hidden' );
		$this->assertTrue( Abilities\is_public( wp_get_ability( 'demo/allowed' ) ) );
		$this->assertFalse( Abilities\is_public( wp_get_ability( 'demo/hidden' ) ) );
	}

	public function test_mcp_hidden_and_internal_abilities_cannot_be_advertised(): void {
		Abilities\register();
		Abilities\set_hidden( 'demo/hidden', true );
		wp_test_register_ability( 'demo/hidden', array( 'meta' => array( 'wmcp_visibility' => 'public' ) ) );
		wp_test_register_ability( 'demo/opted-out', array( 'meta' => array( 'mcp' => array( 'public' => false ), 'wmcp_visibility' => 'public' ) ) );
		wp_test_register_ability( 'mcp-adapter/execute-ability' );
		$settings = new Settings();
		$bridge = new Ability_Bridge( $settings );
		foreach ( wp_get_abilities() as $name => $ability ) {
			$settings->set_visibility_override( $name, 'public' );
			$this->assertSame( 'private', $bridge->resolve_visibility( $name, $ability ) );
			$this->assertNull( $bridge->convert( $name, $ability ) );
		}
	}

	public function test_discovery_rechecks_permissions_after_a_change(): void {
		Abilities\register();
		$GLOBALS['wp_test']['logged_in'] = true;
		wp_test_register_ability( 'demo/read', array( 'permission_callback' => static function () { return current_user_can( 'read' ); } ) );
		$bridge = new Ability_Bridge( new Settings() );
		$this->assertSame( array(), $bridge->get_tools_for_current_user() );
		$GLOBALS['wp_test']['caps'] = array( 'read' );
		$this->assertCount( 1, $bridge->get_tools_for_current_user() );
		$GLOBALS['wp_test']['caps'] = array();
		$this->assertSame( array(), $bridge->get_tools_for_current_user() );
	}

	public function test_defaults_require_sign_in_and_loopback_detection_supports_php74(): void {
		$settings = new Settings();
		$this->assertTrue( $settings->is_enabled() );
		$this->assertFalse( $settings->is_discovery_public() );
		wp_test_register_ability( 'demo/read' );
		$bridge = new Ability_Bridge( $settings );
		$this->assertNull( $bridge->convert( 'demo/read', wp_get_ability( 'demo/read' ) ) );
		foreach ( array( 'localhost:9400', 'site.localhost', '127.0.0.1', '[::1]:9400' ) as $host ) {
			$this->assertTrue( Secure_Context::is_loopback_host( $host ), $host );
		}
		$this->assertFalse( Secure_Context::is_loopback_host( 'localhost.example.com' ) );
		$this->assertFalse( Secure_Context::is_loopback_host( '192.168.1.1' ) );
	}
	public function test_execution_limits_are_shared_across_limiter_instances(): void {
		$first = new \MCP_OAuth\WebMCP\Rate_Limiter();
		for ( $i = 0; $i < 30; ++$i ) {
			$this->assertTrue( $first->check_execution( 1, 'demo/write' ) );
		}
		$next_request = new \MCP_OAuth\WebMCP\Rate_Limiter();
		$this->assertFalse( $next_request->check_execution( 1, 'demo/write' ) );
		$this->assertTrue( $next_request->check_execution( 2, 'demo/write' ) );
	}

	public function test_anonymous_access_requires_filter_and_visibility_is_shared(): void {
		Abilities\register();
		update_option( 'wmcp_discovery_public', true );
		update_option( 'wmcp_tool_visibility', array( 'demo/read' => 'public', 'demo/hidden' => 'private' ) );
		wp_test_register_ability( 'demo/read', array( 'meta' => array( 'wmcp_visibility' => 'public' ) ) );
		wp_test_register_ability( 'demo/hidden' );
		$settings = new Settings();
		$bridge = new Ability_Bridge( $settings );
		$this->assertFalse( $settings->is_discovery_public() );
		$this->assertSame( 'authenticated', $bridge->resolve_visibility( 'demo/read', wp_get_ability( 'demo/read' ) ) );
		$this->assertFalse( Abilities\is_public( wp_get_ability( 'demo/hidden' ) ) );
		add_filter( 'mcp_oauth_allow_anonymous_access', static function () { return true; } );
		$this->assertTrue( $settings->is_discovery_public() );
		$this->assertSame( 'public', $bridge->resolve_visibility( 'demo/read', wp_get_ability( 'demo/read' ) ) );
		Abilities\set_hidden( 'demo/read', true );
		$this->assertSame( 'private', $bridge->resolve_visibility( 'demo/read', wp_get_ability( 'demo/read' ) ) );
		Abilities\set_hidden( 'demo/read', false );
		$this->assertSame( 'public', $bridge->resolve_visibility( 'demo/read', wp_get_ability( 'demo/read' ) ) );
		$this->assertNotContains( 'demo/read', Abilities\hidden() );
	}
}
