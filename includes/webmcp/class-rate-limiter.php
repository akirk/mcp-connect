<?php
/**
 * Rate limiter for tool execution and discovery endpoints.
 *
 * Adapted from WebMCP Abilities 0.8.0 by Code Atlantic (GPL-2.0-or-later).
 *
 * @package mcp-connect
 */

namespace MCP_OAuth\WebMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Provides per-user execution rate limiting and per-IP discovery rate limiting.
 * Uses transients so limits persist between requests without an object-cache drop-in.
 */
class Rate_Limiter {

	/** Cache group for rate limit counters. */
	const CACHE_GROUP = 'wmcp_rate';

	/** Execution rate limit window in seconds. */
	const EXECUTION_WINDOW = 60;

	/** Discovery rate limit window in seconds. */
	const DISCOVERY_WINDOW = 60;

	/**
	 * Check and increment the execution rate limit for a user.
	 * Returns true if the request is allowed, false if rate-limited.
	 *
	 * @param int    $user_id     The executing user's ID.
	 * @param string $ability_name The ability being executed.
	 */
	public function check_execution( int $user_id, string $ability_name ): bool {
		/**
		 * Filter the per-ability execution rate limit.
		 *
		 * @param int    $limit        Max executions per minute. Default 30.
		 * @param string $ability_name The ability name.
		 * @param int    $user_id      The user ID.
		 */
		$limit = (int) apply_filters( 'wmcp_rate_limit', 30, $ability_name, $user_id );

		/**
		 * Hard ceiling on total executions per user per minute regardless of
		 * per-ability overrides.
		 *
		 * @param int $ceiling Default 60.
		 */
		$ceiling = (int) apply_filters( 'wmcp_rate_limit_global_ceiling', 60 );

		$bucket          = (int) floor( time() / self::EXECUTION_WINDOW );
		$per_ability_key = "wmcp_exec_{$bucket}_{$user_id}_" . md5( $ability_name );
		$global_key      = "wmcp_exec_{$bucket}_{$user_id}_global";

		$per_ability_count = (int) get_transient( $per_ability_key );
		$global_count      = (int) get_transient( $global_key );

		if ( $per_ability_count >= $limit || $global_count >= $ceiling ) {
			return false;
		}

		$this->increment( $per_ability_key, self::EXECUTION_WINDOW );
		$this->increment( $global_key, self::EXECUTION_WINDOW );

		return true;
	}

	/**
	 * Check and increment the discovery rate limit for an IP address.
	 * Returns true if the request is allowed, false if rate-limited.
	 *
	 * @param string $ip Client IP address.
	 */
	public function check_discovery( string $ip ): bool {
		/**
		 * Filter the per-IP discovery rate limit.
		 *
		 * @param int    $limit Default 100 requests per minute.
		 * @param string $ip    The client IP address.
		 */
		$limit = (int) apply_filters( 'wmcp_discovery_rate_limit', 100, $ip );

		$key   = 'wmcp_disc_' . (int) floor( time() / self::DISCOVERY_WINDOW ) . '_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		$this->increment( $key, self::DISCOVERY_WINDOW );

		return true;
	}

	/**
	 * Increment a rate limit counter using a simple add-or-increment pattern.
	 * Not perfectly atomic on all backends, but sufficient for rate limiting
	 * where occasional over-counts are acceptable.
	 *
	 * @param string $key    Cache key.
	 * @param int    $window TTL in seconds.
	 */
	private function increment( string $key, int $window ): void {
		$current = (int) get_transient( $key );
		set_transient( $key, $current + 1, 2 * $window );
	}
}
