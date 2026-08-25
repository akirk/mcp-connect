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

	public function test_report_is_empty_without_the_abilities_api(): void {
		$this->assertSame( array(), Abilities\report() );
	}
}
