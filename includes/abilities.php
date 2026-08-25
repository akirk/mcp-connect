<?php
/**
 * Which abilities connected clients can see.
 *
 * The MCP Adapter only lists abilities whose metadata marks them public
 * (`meta.mcp.public`, falling back to `meta.public`). That flag gates nothing
 * here: the MCP endpoint is only reachable by a signed-in user, and every
 * ability enforces its own permission callback for that user. So by default
 * this module marks every ability public for MCP unless it explicitly opted out
 * with `meta.mcp.public = false` or an administrator hid it on the MCP Connect
 * page, and reports what the adapter sees.
 *
 * @package mcp-connect
 */

namespace MCP_OAuth\Abilities;

defined( 'ABSPATH' ) || exit;

const OPTION = 'mcp_oauth_hidden_abilities';

/**
 * Hook the registration filter.
 */
function register(): void {
	add_filter( 'wp_register_ability_args', __NAMESPACE__ . '\expose_ability', 100, 2 );
	add_action( 'rest_api_init', __NAMESPACE__ . '\register_routes' );
}

/**
 * The route the eye toggles on the MCP Connect page call.
 */
function register_routes(): void {
	register_rest_route(
		\MCP_OAuth\REST_NAMESPACE,
		'/abilities/visibility',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\handle_toggle',
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' );
			},
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
 * Mark an ability public for MCP, unless it opted out with an explicit
 * `meta.mcp.public = false` or an administrator hid it.
 *
 * @param array  $args Registration arguments.
 * @param string $name Ability name.
 * @return array
 */
function expose_ability( $args, $name ) {
	// The adapter's own meta-tools (discover/execute) are the MCP surface itself, not abilities to list.
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
 * Every registered ability with the adapter's verdict on it.
 *
 * @return array<int, array{name:string,label:string,type:string,visible:bool,locked:bool,override:bool,reason:string}>
 */
function report(): array {
	if ( ! function_exists( 'wp_get_abilities' ) ) {
		return array();
	}
	$rows   = array();
	$hidden = hidden();
	foreach ( wp_get_abilities() as $ability ) {
		$meta = $ability->get_meta();
		$mcp  = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
		if ( class_exists( '\WP\MCP\Abilities\McpAbilityExposure' ) ) {
			$visible = \WP\MCP\Abilities\McpAbilityExposure::is_public( $ability );
		} else {
			$visible = isset( $mcp['public'] ) ? (bool) $mcp['public'] : ( true === ( $meta['public'] ?? false ) );
		}
		$name   = $ability->get_name();
		$locked = ! $visible && ( 0 === strpos( $name, 'mcp-adapter/' ) || ! in_array( $name, $hidden, true ) );
		if ( in_array( $name, $hidden, true ) && ! $visible ) {
			$reason = __( 'hidden here', 'mcp-oauth' );
		} elseif ( 0 === strpos( $name, 'mcp-adapter/' ) ) {
			$reason = __( 'the adapter’s own tool', 'mcp-oauth' );
		} elseif ( isset( $mcp['public'] ) ) {
			$reason = $visible ? '' : __( 'opted out with meta.mcp.public = false', 'mcp-oauth' );
		} elseif ( array_key_exists( 'public', $meta ) ) {
			$reason = $visible ? __( 'meta.public is true', 'mcp-oauth' ) : __( 'meta.public is false', 'mcp-oauth' );
		} else {
			$reason = __( 'no public flag set', 'mcp-oauth' );
		}
		$rows[] = array(
			'name'     => $name,
			'label'    => $ability->get_label(),
			'type'     => isset( $mcp['type'] ) && is_string( $mcp['type'] ) ? $mcp['type'] : 'tool',
			'visible'  => $visible,
			'locked'   => $locked,
			'override' => in_array( $name, $hidden, true ) && ! $visible,
			'reason'   => $reason,
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
