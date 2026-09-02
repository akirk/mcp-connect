<?php
/**
 * Which abilities are exposed to connected clients.
 *
 * @package mcp-connect
 */

use MCP_OAuth\Abilities;
use PHPUnit\Framework\TestCase;

class Test_Abilities extends TestCase {
	protected function setUp(): void {
		wp_test_reset();
	}

	public function test_abilities_without_a_public_flag_become_public(): void {
		$args = Abilities\expose_ability( array( 'label' => 'x' ), 'travel/list-trips' );
		$this->assertTrue( $args['meta']['mcp']['public'] );
	}

	public function test_meta_public_false_is_overridden_because_the_user_is_signed_in(): void {
		$args = Abilities\expose_ability( array( 'meta' => array( 'public' => false, 'show_in_rest' => false ) ), 'travel/list-trips' );
		$this->assertTrue( $args['meta']['mcp']['public'] );
		$this->assertFalse( $args['meta']['public'], 'The generic flag is left untouched.' );
	}

	public function test_explicit_mcp_opt_out_is_respected(): void {
		$in  = array( 'meta' => array( 'mcp' => array( 'public' => false, 'type' => 'tool' ) ) );
		$out = Abilities\expose_ability( $in, 'akismet/comment-check' );
		$this->assertSame( $in, $out );
	}

	public function test_adapter_meta_tools_are_left_alone(): void {
		$in = array( 'meta' => array( 'public' => false ) );
		$this->assertSame( $in, Abilities\expose_ability( $in, 'mcp-adapter/execute-ability' ) );
	}

	public function test_hidden_abilities_are_marked_not_public(): void {
		Abilities\set_hidden( 'travel/delete-plan', true );
		$this->assertSame( array( 'travel/delete-plan' ), Abilities\hidden() );

		$hidden = Abilities\expose_ability( array(), 'travel/delete-plan' );
		$this->assertFalse( $hidden['meta']['mcp']['public'] );
		$shown = Abilities\expose_ability( array(), 'travel/list-trips' );
		$this->assertTrue( $shown['meta']['mcp']['public'] );

		Abilities\set_hidden( 'travel/delete-plan', false );
		$this->assertSame( array(), Abilities\hidden() );
		$this->assertTrue( Abilities\expose_ability( array(), 'travel/delete-plan' )['meta']['mcp']['public'] );
	}

	public function test_set_hidden_is_idempotent(): void {
		Abilities\set_hidden( 'a/b', true );
		Abilities\set_hidden( 'a/b', true );
		Abilities\set_hidden( 'c/d', true );
		$this->assertSame( array( 'a/b', 'c/d' ), Abilities\hidden() );
	}

	public function test_hidden_list_is_filterable_and_tolerates_garbage(): void {
		update_option( Abilities\OPTION, array( 'x/y', 5, null ) );
		add_filter(
			'mcp_oauth_hidden_abilities',
			static function ( array $hidden ): array {
				$hidden[] = 'forced/hidden';
				return $hidden;
			}
		);
		$this->assertSame( array( 'x/y', 'forced/hidden' ), Abilities\hidden() );
		update_option( Abilities\OPTION, 'not an array' );
		$this->assertSame( array( 'forced/hidden' ), Abilities\hidden() );
	}

	public function test_non_array_args_pass_through(): void {
		$this->assertNull( Abilities\expose_ability( null, 'x/y' ) );
	}

	public function test_report_is_empty_when_no_abilities_are_registered(): void {
		$this->assertSame( array(), Abilities\report() );
	}

	const META_TOOLS = array( 'mcp-adapter/discover-abilities', 'mcp-adapter/get-ability-info', 'mcp-adapter/execute-ability' );

	/**
	 * The default server's tool list, as the adapter would hand it over.
	 */
	private function tools(): array {
		$config = Abilities\expose_abilities_as_tools(
			array(
				'tools'     => self::META_TOOLS,
				'resources' => array( 'travel/itinerary' ),
			)
		);
		return $config['tools'];
	}

	public function test_nothing_is_promoted_by_default(): void {
		Abilities\register();
		wp_test_register_ability( 'travel/list-trips', array( 'label' => 'List trips' ) );

		$this->assertSame( self::META_TOOLS, $this->tools(), 'the meta-tools alone, so the adapter behaves as it always did' );
	}

	public function test_a_promoted_ability_becomes_a_tool_of_its_own(): void {
		Abilities\register();
		Abilities\set_direct_tool( 'travel/list-trips', true );
		wp_test_register_ability( 'travel/list-trips', array( 'label' => 'List trips' ) );
		wp_test_register_ability( 'travel/book-trip', array( 'label' => 'Book a trip' ) );

		$this->assertSame( array_merge( self::META_TOOLS, array( 'travel/list-trips' ) ), $this->tools() );
	}

	public function test_the_meta_tools_stay_so_nothing_becomes_unreachable(): void {
		Abilities\register();
		Abilities\set_direct_tool( 'travel/list-trips', true );
		wp_test_register_ability( 'travel/list-trips', array() );

		$this->assertSame( self::META_TOOLS, array_slice( $this->tools(), 0, 3 ) );
	}

	public function test_hidden_and_opted_out_abilities_are_not_tools_even_when_promoted(): void {
		Abilities\register();
		Abilities\set_hidden( 'travel/delete-plan', true );
		Abilities\set_direct_tool( 'travel/delete-plan', true );
		Abilities\set_direct_tool( 'akismet/comment-check', true );
		Abilities\set_direct_tool( 'travel/list-trips', true );
		wp_test_register_ability( 'travel/list-trips', array() );
		wp_test_register_ability( 'travel/delete-plan', array() );
		wp_test_register_ability( 'akismet/comment-check', array( 'meta' => array( 'mcp' => array( 'public' => false ) ) ) );

		$this->assertSame( array_merge( self::META_TOOLS, array( 'travel/list-trips' ) ), $this->tools() );
	}

	public function test_promoted_resources_and_prompts_are_left_to_the_adapter(): void {
		Abilities\register();
		Abilities\set_direct_tool( 'travel/itinerary', true );
		Abilities\set_direct_tool( 'travel/plan-prompt', true );
		wp_test_register_ability( 'travel/itinerary', array( 'meta' => array( 'mcp' => array( 'type' => 'resource' ) ) ) );
		wp_test_register_ability( 'travel/plan-prompt', array( 'meta' => array( 'mcp' => array( 'type' => 'prompt' ) ) ) );

		$this->assertSame( self::META_TOOLS, $this->tools() );
	}

	public function test_the_tool_list_is_filterable(): void {
		Abilities\register();
		Abilities\set_direct_tool( 'travel/list-trips', true );
		wp_test_register_ability( 'travel/list-trips', array() );
		add_filter(
			'mcp_oauth_tools',
			static function ( array $tools, array $meta_tools, array $promoted ): array {
				return $promoted;
			},
			10,
			3
		);
		$this->assertSame( array( 'travel/list-trips' ), $this->tools(), 'the meta-tools can be dropped entirely' );
	}

	public function test_the_promoted_list_is_filterable_and_tolerates_garbage(): void {
		update_option( Abilities\TOOLS_OPTION, array( 'travel/list-trips', 7, null ) );
		$this->assertSame( array( 'travel/list-trips' ), Abilities\direct_tools() );
		update_option( Abilities\TOOLS_OPTION, 'not an array' );
		$this->assertSame( array(), Abilities\direct_tools() );
	}

	public function test_set_direct_tool_is_idempotent(): void {
		Abilities\set_direct_tool( 'a/b', true );
		Abilities\set_direct_tool( 'a/b', true );
		Abilities\set_direct_tool( 'c/d', true );
		$this->assertSame( array( 'a/b', 'c/d' ), Abilities\direct_tools() );
		Abilities\set_direct_tool( 'a/b', false );
		$this->assertSame( array( 'c/d' ), Abilities\direct_tools() );
	}

	public function test_a_non_array_config_passes_through(): void {
		$this->assertNull( Abilities\expose_abilities_as_tools( null ) );
	}

	/**
	 * Promote or demote through the REST route the checkbox calls.
	 */
	private function toggle_tool( string $ability, bool $expose ) {
		return Abilities\handle_tool_toggle(
			new WP_REST_Request( 'POST', '/abilities/tool', array( 'ability' => $ability, 'expose' => $expose ) )
		);
	}

	public function test_the_route_promotes_and_demotes(): void {
		Abilities\register();
		wp_test_register_ability( 'travel/list-trips', array() );
		wp_test_register_ability( 'travel/book-trip', array() );

		$this->assertSame( 1, $this->toggle_tool( 'travel/list-trips', true )->get_data()['direct'], 'the new count is reported back' );
		$this->assertSame( 2, $this->toggle_tool( 'travel/book-trip', true )->get_data()['direct'] );
		$this->assertSame( array( 'travel/list-trips', 'travel/book-trip' ), Abilities\direct_tools() );

		$this->assertSame( 1, $this->toggle_tool( 'travel/book-trip', false )->get_data()['direct'] );
		$this->assertSame( array( 'travel/list-trips' ), Abilities\direct_tools() );
	}

	public function test_the_route_refuses_abilities_that_cannot_be_tools(): void {
		Abilities\register();
		Abilities\set_hidden( 'travel/delete-plan', true );
		wp_test_register_ability( 'travel/delete-plan', array() );
		wp_test_register_ability( 'travel/itinerary', array( 'meta' => array( 'mcp' => array( 'type' => 'resource' ) ) ) );

		$this->assertSame( 'mcp_oauth_not_promotable', $this->toggle_tool( 'travel/delete-plan', true )->get_error_code() );
		$this->assertSame( 'mcp_oauth_not_promotable', $this->toggle_tool( 'travel/itinerary', true )->get_error_code() );
		$this->assertSame( 'mcp_oauth_unknown_ability', $this->toggle_tool( 'travel/nope', true )->get_error_code() );
		$this->assertSame( array(), Abilities\direct_tools() );
	}

	public function test_a_hidden_ability_can_still_be_demoted(): void {
		Abilities\register();
		Abilities\set_direct_tool( 'travel/list-trips', true );
		Abilities\set_hidden( 'travel/list-trips', true );
		wp_test_register_ability( 'travel/list-trips', array() );

		$this->toggle_tool( 'travel/list-trips', false );
		$this->assertSame( array(), Abilities\direct_tools(), 'demoting never asks whether the ability could be promoted' );
	}
}
