<?php
/**
 * A fake MCP Adapter registry so server-dependent logic can be unit tested.
 *
 * @package mcp-connect
 */

// phpcs:ignoreFile

namespace WP\MCP\Core;

class McpServer {
	private $id; private $ns; private $route; private $name;
	public function __construct( string $id, string $ns, string $route, string $name ) {
		$this->id = $id; $this->ns = $ns; $this->route = $route; $this->name = $name;
	}
	public function get_server_id(): string { return $this->id; }
	public function get_server_route_namespace(): string { return $this->ns; }
	public function get_server_route(): string { return $this->route; }
	public function get_server_name(): string { return $this->name; }
}

class McpAdapter {
	private static $instance;
	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	public function init(): void {}
	public function get_servers(): array { return $GLOBALS['wp_test']['servers']; }
}

namespace WP\MCP\Abilities;

class McpAbilityExposure {
	public static function is_public( $ability ): bool {
		$meta = $ability->get_meta();
		$mcp  = $meta['mcp'] ?? array();
		if ( isset( $mcp['public'] ) ) {
			return (bool) $mcp['public'];
		}
		return true === ( $meta['public'] ?? false );
	}
}
