<?php
/**
 * Catalog of AI clients and how each one connects to a remote MCP server.
 *
 * Every entry describes one client: a one-click link, a terminal command, a
 * config snippet with the file it belongs in, or a short list of in-app steps.
 * Clients whose OAuth support for remote servers is not established go through
 * the `mcp-remote` bridge, which runs the browser sign-in on their behalf.
 *
 * @package mcp-connect
 */

namespace MCP_OAuth\Clients;

defined( 'ABSPATH' ) || exit;

/**
 * Single-quote a shell argument.
 */
function sh( string $value ): string {
	return "'" . str_replace( "'", "'\\''", $value ) . "'";
}

/**
 * Pretty JSON.
 */
function json( array $value ): string {
	return (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
}

/**
 * A short identifier for this site used as the server name in client configs.
 */
function server_key(): string {
	$key = sanitize_title( get_bloginfo( 'name' ) );
	$key = preg_replace( '/[^a-z0-9-]/', '', $key );
	return '' !== $key ? $key : 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- A config key, not prose.
}

/**
 * The display name of the connector.
 */
function display_name(): string {
	$name = trim( get_bloginfo( 'name' ) );
	return '' !== $name ? $name : __( 'WordPress', 'mcp-oauth' );
}

/**
 * Whether cloud clients (Claude.ai, ChatGPT) can reach this site at all.
 */
function reachable_from_cloud(): bool {
	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( 'local' === wp_get_environment_type() || \MCP_OAuth\Register\is_loopback( $host ) ) {
		return false;
	}
	if ( '.local' === substr( $host, -6 ) || '.localhost' === substr( $host, -10 ) || '.test' === substr( $host, -5 ) || false === strpos( $host, '.' ) ) {
		return false;
	}
	$ip = filter_var( $host, FILTER_VALIDATE_IP );
	if ( false !== $ip && false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		return false;
	}
	return true;
}

/**
 * The claude.ai "add custom connector" link with name and URL pre-filled.
 */
function claude_connector_link( string $url ): string {
	return 'https://claude.ai/customize/connectors?modal=add-custom-connector&connectorName=' . rawurlencode( display_name() ) . '&connectorUrl=' . rawurlencode( $url );
}

/**
 * The Cursor one-click install link.
 */
function cursor_deeplink( string $url ): string {
	return 'cursor://anysphere.cursor-deeplink/mcp/install?name=' . rawurlencode( server_key() ) . '&config=' . base64_encode( (string) wp_json_encode( array( 'url' => $url ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

/**
 * The mcp-remote bridge as a stdio server object.
 */
function bridge_server( string $url ): array {
	return array(
		'command' => 'npx',
		'args'    => array( '-y', 'mcp-remote', $url ),
	);
}

/**
 * The catalog for one MCP endpoint URL.
 *
 * Each entry: name, optional `cloud` flag (connects from the vendor's servers),
 * optional `link` (one-click URL + label), optional `command` (shell), optional
 * `snippet` with `paths` (config file locations), optional `steps`, optional `note`.
 *
 * @return array<string, array<string, mixed>>
 */
function catalog( string $url ): array {
	$key      = server_key();
	$name     = display_name();
	$http     = array( $key => array( 'url' => $url ) );
	$http_t   = array(
		$key => array(
			'type' => 'http',
			'url'  => $url,
		),
	);
	$bridge   = array( $key => bridge_server( $url ) );
	$add_to   = static function ( string $file ): string {
		/* translators: %s: file name */
		return sprintf( __( 'Add to %s', 'mcp-oauth' ), '<code>' . $file . '</code>' );
	};
	$connector_steps = static function ( string $app ) use ( $name, $url ): array {
		return array(
			/* translators: %s: app name */
			sprintf( __( 'In %s, open Settings → Connectors.', 'mcp-oauth' ), $app ),
			__( 'Choose “Add custom connector”.', 'mcp-oauth' ),
			/* translators: %s: connector name */
			sprintf( __( 'Name it “%s” and paste your MCP server URL (shown above) as the remote MCP server URL. Leave client ID and secret empty.', 'mcp-oauth' ), $name ),
			__( 'Save, then click Connect and sign in to this site when the browser opens.', 'mcp-oauth' ),
		);
	};

	return array(
		'claude-ai'      => array(
			'name'  => 'Claude.ai',
			'cloud' => true,
			'link'  => array(
				'url'   => claude_connector_link( $url ),
				'label' => __( 'Add to Claude.ai', 'mcp-oauth' ),
			),
			'steps' => $connector_steps( 'claude.ai' ),
			'note'  => __( 'Custom connectors need a Pro, Max, Team or Enterprise plan. Connectors added on claude.ai are also available in Claude Desktop and the mobile apps.', 'mcp-oauth' ),
		),
		'claude-desktop' => array(
			'name'  => 'Claude Desktop',
			'steps' => $connector_steps( 'Claude Desktop' ),
		),
		'claude-code'    => array(
			'name'    => 'Claude Code',
			'command' => 'claude mcp add --transport http --scope user ' . sh( $key ) . ' ' . sh( $url ),
			'hint'    => __( 'Run in a terminal, then type /mcp in Claude Code to sign in.', 'mcp-oauth' ),
		),
		'chatgpt'        => array(
			'name'  => 'ChatGPT',
			'cloud' => true,
			'steps' => array(
				__( 'In ChatGPT, open Settings → Apps & Connectors → Advanced settings and enable Developer mode.', 'mcp-oauth' ),
				__( 'Go back to Apps & Connectors and choose “Create”.', 'mcp-oauth' ),
				/* translators: %s: connector name */
				sprintf( __( 'Name it “%s”, paste your MCP server URL (shown above) as the MCP server URL and keep Authentication on OAuth.', 'mcp-oauth' ), $name ),
				__( 'Confirm the warning about unverified apps, create the app, and sign in to this site when the browser opens.', 'mcp-oauth' ),
			),
			'note'  => __( 'Developer mode is required for apps OpenAI has not reviewed, which any connector to your own site always is.', 'mcp-oauth' ),
		),
		'codex'          => array(
			'name'    => 'Codex CLI',
			'command' => 'codex mcp add ' . sh( $key ) . ' --url ' . sh( $url ) . ' && codex mcp login ' . sh( $key ),
			'hint'    => __( 'Run in a terminal; the second command opens the browser to sign in.', 'mcp-oauth' ),
		),
		'cursor'         => array(
			'name'    => 'Cursor',
			'link'    => array(
				'url'   => cursor_deeplink( $url ),
				'label' => __( 'Add to Cursor', 'mcp-oauth' ),
			),
			'snippet' => json( array( 'mcpServers' => $http ) ),
			'hint'    => $add_to( 'mcp.json' ),
			'paths'   => array(
				__( 'Global', 'mcp-oauth' )  => '~/.cursor/mcp.json',
				__( 'Project', 'mcp-oauth' ) => '.cursor/mcp.json',
			),
		),
		'vscode'         => array(
			'name'    => 'VS Code (Copilot)',
			'snippet' => json( array( 'servers' => $http_t ) ),
			'hint'    => $add_to( 'mcp.json' ),
			'paths'   => array(
				__( 'Workspace', 'mcp-oauth' ) => '.vscode/mcp.json',
				__( 'User', 'mcp-oauth' )      => __( 'Command palette → “MCP: Open User Configuration”', 'mcp-oauth' ),
			),
		),
		'windsurf'       => array(
			'name'    => 'Windsurf',
			'snippet' => json( array( 'mcpServers' => array( $key => array( 'serverUrl' => $url ) ) ) ),
			'hint'    => $add_to( 'mcp_config.json' ),
			'paths'   => array(
				'macOS / Linux' => '~/.codeium/windsurf/mcp_config.json',
				'Windows'       => '%USERPROFILE%\\.codeium\\windsurf\\mcp_config.json',
			),
		),
		'gemini-cli'     => array(
			'name'    => 'Gemini CLI',
			'snippet' => json( array( 'mcpServers' => array( $key => array( 'httpUrl' => $url ) ) ) ),
			'hint'    => $add_to( 'settings.json' ),
			'paths'   => array(
				__( 'User', 'mcp-oauth' )    => '~/.gemini/settings.json',
				__( 'Project', 'mcp-oauth' ) => '.gemini/settings.json',
			),
		),
		'antigravity'    => array(
			'name'    => 'Antigravity',
			'snippet' => json( array( 'mcpServers' => array( $key => array( 'serverUrl' => $url ) ) ) ),
			'hint'    => $add_to( 'mcp_config.json' ),
			'paths'   => array(
				__( 'Global', 'mcp-oauth' )    => '~/.gemini/config/mcp_config.json',
				__( 'Workspace', 'mcp-oauth' ) => '.agents/mcp_config.json',
			),
		),
		'zed'            => array(
			'name'    => 'Zed',
			'snippet' => json(
				array(
					'context_servers' => array(
						$key => array(
							'source'  => 'custom',
							'enabled' => true,
						) + bridge_server( $url ),
					),
				)
			),
			'hint'    => $add_to( 'settings.json' ),
			'paths'   => array( 'macOS / Linux' => '~/.config/zed/settings.json' ),
			'bridge'  => true,
		),
		'cline'          => array(
			'name'    => 'Cline',
			'snippet' => json( array( 'mcpServers' => $bridge ) ),
			'hint'    => $add_to( 'cline_mcp_settings.json' ),
			'paths'   => array( __( 'Via UI', 'mcp-oauth' ) => __( 'Cline sidebar → MCP Servers → Configure MCP Servers', 'mcp-oauth' ) ),
			'bridge'  => true,
		),
		'roo-code'       => array(
			'name'    => 'Roo Code',
			'snippet' => json( array( 'mcpServers' => $bridge ) ),
			'hint'    => $add_to( 'mcp.json' ),
			'paths'   => array( __( 'Project', 'mcp-oauth' ) => '.roo/mcp.json' ),
			'bridge'  => true,
		),
		'opencode'       => array(
			'name'    => 'OpenCode',
			'snippet' => json(
				array(
					'mcp' => array(
						$key => array(
							'type'    => 'local',
							'command' => array( 'npx', '-y', 'mcp-remote', $url ),
						),
					),
				)
			),
			'hint'    => $add_to( 'opencode.json' ),
			'paths'   => array(
				__( 'Project', 'mcp-oauth' ) => 'opencode.json',
				__( 'Global', 'mcp-oauth' )  => '~/.config/opencode/opencode.json',
			),
			'bridge'  => true,
		),
		'other'          => array(
			'name'    => __( 'Any other MCP client', 'mcp-oauth' ),
			'snippet' => json( array( 'mcpServers' => $bridge ) ),
			'hint'    => __( 'Clients that support remote MCP servers with OAuth only need the URL above. For clients that only run local (stdio) servers, use the mcp-remote bridge:', 'mcp-oauth' ),
			'bridge'  => true,
		),
	);
}
