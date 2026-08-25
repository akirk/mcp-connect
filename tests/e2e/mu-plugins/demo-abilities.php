<?php
/**
 * Test fixtures for the e2e suite: relaxed registration limits, and two demo
 * abilities — one not marked public (the common case for plugins) and one that
 * explicitly opts out of MCP.
 *
 * @package mcp-connect
 */

// The suite registers a client per test; the production limits would trip after ten.
add_filter(
	'mcp_oauth_registration_limits',
	function () {
		return array(
			'per_hour'    => 1000,
			'max_clients' => 1000,
		);
	}
);

add_action(
	'wp_abilities_api_init',
	function () {
		wp_register_ability(
			'demo/list-trips',
			array(
				'label'               => 'List trips',
				'description'         => 'Lists demo trips.',
				'category'            => 'site',
				'execute_callback'    => function () {
					return array( 'trips' => array( 'Vienna', 'Lisbon' ) );
				},
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array(
					'public'       => false,
					'show_in_rest' => false,
				),
			)
		);
		wp_register_ability(
			'demo/opted-out',
			array(
				'label'               => 'Opted out',
				'description'         => 'Never for MCP.',
				'category'            => 'site',
				'execute_callback'    => '__return_empty_array',
				'permission_callback' => '__return_true',
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array(
					'mcp'          => array( 'public' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}
);
