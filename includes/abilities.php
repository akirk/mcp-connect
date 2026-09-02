<?php
/**
 * Which abilities connected clients can see, and how they see them.
 *
 * The MCP Adapter only lists abilities whose metadata marks them public
 * (`meta.mcp.public`, falling back to `meta.public`). That flag gates nothing
 * here: the MCP endpoint is only reachable by a signed-in user, and every
 * ability enforces its own permission callback for that user. So by default
 * this module marks every ability public for MCP unless it explicitly opted out
 * with `meta.mcp.public = false` or an administrator hid it on the MCP Connect
 * page, and reports what the adapter sees.
 *
 * Exposure and promotion are two separate switches. Exposure decides whether a
 * client can reach an ability at all, through the adapter's discover/execute
 * meta-tools. Promotion additionally registers an exposed ability as a tool of
 * its own, so a client calls it directly instead of naming it in a string. The
 * meta-tools stay for the long tail; promotion is for the handful of abilities
 * worth spending a client's tool budget on.
 *
 * @package mcp-connect
 */

namespace MCP_OAuth\Abilities;

defined( 'ABSPATH' ) || exit;

const OPTION = 'mcp_oauth_hidden_abilities';

const TOOLS_OPTION = 'mcp_oauth_direct_tools';

/**
 * Hook the registration filter.
 */
function register(): void {
	add_filter( 'wp_register_ability_args', __NAMESPACE__ . '\expose_ability', 100, 2 );
	add_filter( 'mcp_adapter_default_server_config', __NAMESPACE__ . '\expose_abilities_as_tools' );
	add_action( 'rest_api_init', __NAMESPACE__ . '\register_routes' );
}

/**
 * The routes the eye and the tool checkboxes on the MCP Connect page call.
 */
function register_routes(): void {
	$admin_only = static function (): bool {
		return current_user_can( 'manage_options' );
	};
	register_rest_route(
		\MCP_OAuth\REST_NAMESPACE,
		'/abilities/visibility',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\handle_toggle',
			'permission_callback' => $admin_only,
			'args'                => array(
				'ability' => array(
					'type'     => 'string',
					'required' => true,
				),
				'hide'    => array(
					'type'     => 'boolean',
					'required' => true,
				),
			),
		)
	);
	register_rest_route(
		\MCP_OAuth\REST_NAMESPACE,
		'/abilities/tool',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\handle_tool_toggle',
			'permission_callback' => $admin_only,
			'args'                => array(
				'ability' => array(
					'type'     => 'string',
					'required' => true,
				),
				'expose'  => array(
					'type'     => 'boolean',
					'required' => true,
				),
			),
		)
	);
}

/**
 * Hide or show an ability and report the new state.
 *
 * @param \WP_REST_Request $request Request.
 * @return \WP_REST_Response|\WP_Error
 */
function handle_toggle( $request ) {
	$name = (string) $request->get_param( 'ability' );
	$hide = (bool) $request->get_param( 'hide' );
	$row  = null;
	foreach ( report() as $candidate ) {
		if ( $candidate['name'] === $name ) {
			$row = $candidate;
		}
	}
	if ( ! $row ) {
		return new \WP_Error( 'mcp_oauth_unknown_ability', __( 'Unknown ability.', 'mcp-oauth' ), array( 'status' => 404 ) );
	}
	if ( $row['locked'] ) {
		return new \WP_Error( 'mcp_oauth_locked_ability', __( 'This ability opted out of MCP and cannot be shown.', 'mcp-oauth' ), array( 'status' => 400 ) );
	}
	set_hidden( $name, $hide );

	// Abilities were registered before this change, so derive the new state instead of re-reading it.
	$rows    = report();
	$visible = count( array_filter( array_column( $rows, 'visible' ) ) );
	if ( $row['visible'] === $hide ) {
		$visible += $hide ? -1 : 1;
	}
	return new \WP_REST_Response(
		array(
			'ability'  => $name,
			'visible'  => ! $hide,
			'reason'   => $hide ? __( 'hidden here', 'mcp-oauth' ) : '',
			'override' => $hide,
			'count'    => $visible,
			'total'    => count( $rows ),
		)
	);
}

/**
 * Ability names an administrator hid from connected clients.
 *
 * @return string[]
 */
function hidden(): array {
	$hidden = get_option( OPTION, array() );
	$hidden = is_array( $hidden ) ? array_values( array_filter( $hidden, 'is_string' ) ) : array();

	/**
	 * Filters the abilities hidden from MCP clients.
	 *
	 * @param string[] $hidden Ability names.
	 */
	return (array) apply_filters( 'mcp_oauth_hidden_abilities', $hidden );
}

/**
 * Hide or show one ability.
 */
function set_hidden( string $name, bool $hide ): void {
	$hidden = get_option( OPTION, array() );
	$hidden = is_array( $hidden ) ? array_values( array_filter( $hidden, 'is_string' ) ) : array();
	$hidden = array_values( array_diff( $hidden, array( $name ) ) );
	if ( $hide ) {
		$hidden[] = $name;
	}
	update_option( OPTION, $hidden, false );
}

/**
 * Ability names an administrator promoted to tools of their own.
 *
 * @return string[]
 */
function direct_tools(): array {
	$names = get_option( TOOLS_OPTION, array() );
	$names = is_array( $names ) ? array_values( array_filter( $names, 'is_string' ) ) : array();

	/**
	 * Filters the abilities registered as tools of their own.
	 *
	 * @param string[] $names Ability names.
	 */
	return (array) apply_filters( 'mcp_oauth_direct_tools', $names );
}

/**
 * Promote one ability to a tool of its own, or demote it again.
 */
function set_direct_tool( string $name, bool $expose ): void {
	$names = get_option( TOOLS_OPTION, array() );
	$names = is_array( $names ) ? array_values( array_filter( $names, 'is_string' ) ) : array();
	$names = array_values( array_diff( $names, array( $name ) ) );
	if ( $expose ) {
		$names[] = $name;
	}
	update_option( TOOLS_OPTION, $names, false );
}

/**
 * Promote one ability to a tool of its own, or demote it, and report the new state.
 *
 * @param \WP_REST_Request $request Request.
 * @return \WP_REST_Response|\WP_Error
 */
function handle_tool_toggle( $request ) {
	$name   = (string) $request->get_param( 'ability' );
	$expose = (bool) $request->get_param( 'expose' );
	$row    = null;
	foreach ( report() as $candidate ) {
		if ( $candidate['name'] === $name ) {
			$row = $candidate;
		}
	}
	if ( ! $row ) {
		return new \WP_Error( 'mcp_oauth_unknown_ability', __( 'Unknown ability.', 'mcp-oauth' ), array( 'status' => 404 ) );
	}
	// Demoting always works; promoting needs an ability that can be a tool at all.
	if ( $expose && ! $row['promotable'] ) {
		return new \WP_Error( 'mcp_oauth_not_promotable', __( 'This ability cannot be exposed as a tool of its own.', 'mcp-oauth' ), array( 'status' => 400 ) );
	}
	set_direct_tool( $name, $expose );

	// Promotion is read from the option at report time, so the fresh report already counts this change.
	return new \WP_REST_Response(
		array(
			'ability' => $name,
			'expose'  => $expose,
			'direct'  => count( array_filter( array_column( report(), 'direct' ) ) ),
		)
	);
}

/**
 * Mark an ability public for MCP, unless it opted out with an explicit
 * `meta.mcp.public = false` or an administrator hid it.
 *
 * @param array  $args Registration arguments.
 * @param string $name Ability name.
 * @return array
 */
function expose_ability( $args, $name ) {
	// The adapter's own meta-tools (discover/execute) are replaced by the abilities themselves, so leave them alone.
	if ( ! is_array( $args ) || 0 === strpos( (string) $name, 'mcp-adapter/' ) ) {
		return $args;
	}
	$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
	$mcp  = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
	if ( isset( $mcp['public'] ) && false === (bool) $mcp['public'] ) {
		return $args;
	}
	$mcp['public'] = ! in_array( (string) $name, hidden(), true );
	$meta['mcp']   = $mcp;
	$args['meta']  = $meta;
	return $args;
}

/**
 * Whether the adapter exposes an ability through the default server.
 *
 * @param \WP_Ability $ability Ability.
 */
function is_public( $ability ): bool {
	if ( class_exists( '\WP\MCP\Abilities\McpAbilityExposure' ) ) {
		return \WP\MCP\Abilities\McpAbilityExposure::is_public( $ability );
	}
	$meta = $ability->get_meta();
	$mcp  = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
	if ( isset( $mcp['public'] ) ) {
		return (bool) $mcp['public'];
	}
	return true === ( $meta['public'] ?? false );
}

/**
 * The MCP type an ability asks to be exposed as: tool, resource or prompt.
 *
 * @param \WP_Ability $ability Ability.
 */
function type( $ability ): string {
	$meta = $ability->get_meta();
	$mcp  = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
	return isset( $mcp['type'] ) && is_string( $mcp['type'] ) ? $mcp['type'] : 'tool';
}

/**
 * Whether an ability could be registered as a tool of its own.
 *
 * The adapter's own meta-tools are the fallback path, not candidates for it;
 * resource- and prompt-type abilities are registered by the adapter under those
 * types; and an ability no client can reach cannot be a tool either.
 *
 * @param \WP_Ability $ability Ability.
 */
function is_promotable( $ability ): bool {
	return 0 !== strpos( $ability->get_name(), 'mcp-adapter/' ) && 'tool' === type( $ability ) && is_public( $ability );
}

/**
 * Add the promoted abilities to the default server's tool list.
 *
 * The adapter's default server ships three meta-tools — discover-abilities,
 * get-ability-info and execute-ability — through which a client can reach every
 * exposed ability by naming it in a string. That keeps the tool list short on a
 * site with a hundred abilities, at the cost of two round trips and a schema the
 * client never sees.
 *
 * Abilities an administrator ticked on the MCP Connect page are registered as
 * tools in their own right on top of that, each with its own name, description
 * and input schema, so a client calls the ones that matter in a single step. The
 * meta-tools stay either way, so nothing becomes unreachable by promoting
 * nothing.
 *
 * @param mixed $config Default server configuration.
 * @return mixed
 */
function expose_abilities_as_tools( $config ) {
	if ( ! is_array( $config ) || ! function_exists( 'wp_get_abilities' ) ) {
		return $config;
	}
	$meta_tools = isset( $config['tools'] ) && is_array( $config['tools'] ) ? $config['tools'] : array();
	$promoted   = direct_tools();
	$tools      = array();
	foreach ( wp_get_abilities() as $ability ) {
		$name = $ability->get_name();
		if ( in_array( $name, $promoted, true ) && is_promotable( $ability ) ) {
			$tools[] = $name;
		}
	}

	/**
	 * Filters the tools registered on the default MCP server.
	 *
	 * @param string[] $tools      The final list: the adapter's meta-tools plus the promoted abilities.
	 * @param string[] $meta_tools The tool list the adapter had configured.
	 * @param string[] $promoted   The abilities promoted to tools of their own.
	 */
	$config['tools'] = array_values( array_unique( (array) apply_filters( 'mcp_oauth_tools', array_merge( $meta_tools, $tools ), $meta_tools, $tools ) ) );
	return $config;
}

/**
 * Every registered ability with the adapter's verdict on it.
 *
 * @return array<int, array{name:string,label:string,type:string,visible:bool,locked:bool,override:bool,reason:string,direct:bool,promotable:bool,tool_type:bool}>
 */
function report(): array {
	if ( ! function_exists( 'wp_get_abilities' ) ) {
		return array();
	}
	$rows     = array();
	$hidden   = hidden();
	$promoted = direct_tools();
	foreach ( wp_get_abilities() as $ability ) {
		$meta    = $ability->get_meta();
		$mcp     = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
		$visible = is_public( $ability );
		$name    = $ability->get_name();
		$locked  = ! $visible && ( 0 === strpos( $name, 'mcp-adapter/' ) || ! in_array( $name, $hidden, true ) );
		if ( in_array( $name, $hidden, true ) && ! $visible ) {
			$reason = __( 'hidden here', 'mcp-oauth' );
		} elseif ( 0 === strpos( $name, 'mcp-adapter/' ) ) {
			$reason = __( 'the adapter’s own meta-tool', 'mcp-oauth' );
		} elseif ( isset( $mcp['public'] ) ) {
			$reason = $visible ? '' : __( 'opted out with meta.mcp.public = false', 'mcp-oauth' );
		} elseif ( array_key_exists( 'public', $meta ) ) {
			$reason = $visible ? __( 'meta.public is true', 'mcp-oauth' ) : __( 'meta.public is false', 'mcp-oauth' );
		} else {
			$reason = __( 'no public flag set', 'mcp-oauth' );
		}
		$rows[] = array(
			'name'       => $name,
			'label'      => $ability->get_label(),
			'type'       => type( $ability ),
			'visible'    => $visible,
			'locked'     => $locked,
			'override'   => in_array( $name, $hidden, true ) && ! $visible,
			'reason'     => $reason,
			'direct'     => in_array( $name, $promoted, true ),
			'promotable' => is_promotable( $ability ),
			'tool_type'  => 0 !== strpos( $name, 'mcp-adapter/' ) && 'tool' === type( $ability ),
		);
	}
	usort(
		$rows,
		static function ( array $a, array $b ): int {
			return strcmp( $a['name'], $b['name'] );
		}
	);
	return $rows;
}
