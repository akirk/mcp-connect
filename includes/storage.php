<?php
/**
 * Database storage for clients, authorization codes and tokens.
 *
 * Every credential (authorization code, access token, refresh token) is stored
 * only as its SHA-256 hash, so reading the database never yields a usable one.
 * Tokens are opaque random strings; validation is a hash lookup.
 *
 * @package mcp-connect
 */

namespace MCP_OAuth\Storage;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- This is the plugin's own storage.
// phpcs:disable WordPress.DB.PreparedSQL -- Table names are built by table() from $wpdb->prefix; every value goes through prepare().

defined( 'ABSPATH' ) || exit;

const SCHEMA_OPTION  = 'mcp_oauth_schema_version';
const SCHEMA_VERSION = '1';
const GC_HOOK        = 'mcp_oauth_gc';

const CODE_TTL          = 5 * MINUTE_IN_SECONDS;
const ACCESS_TOKEN_TTL  = HOUR_IN_SECONDS;
const REFRESH_TOKEN_TTL = 14 * DAY_IN_SECONDS;

/**
 * A table name with the site prefix.
 */
function table( string $name ): string {
	global $wpdb;
	return $wpdb->prefix . 'mcp_oauth_' . $name;
}

/**
 * Hash a credential for storage and lookup.
 */
function hash_credential( string $raw ): string {
	return hash( 'sha256', $raw );
}

/**
 * Mint an opaque credential.
 */
function random_credential(): string {
	return bin2hex( random_bytes( 32 ) );
}

/**
 * UTC timestamp in MySQL format.
 */
function now( int $offset = 0 ): string {
	return gmdate( 'Y-m-d H:i:s', time() + $offset );
}

/**
 * Create or upgrade the tables.
 */
function maybe_install(): void {
	if ( get_option( SCHEMA_OPTION ) === SCHEMA_VERSION ) {
		schedule();
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	global $wpdb;
	$collate = $wpdb->get_charset_collate();

	dbDelta(
		'CREATE TABLE ' . table( 'clients' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		client_id VARCHAR(64) NOT NULL,
		client_name VARCHAR(191) NOT NULL,
		redirect_uris TEXT NOT NULL,
		client_uri TEXT NOT NULL,
		ip_hash CHAR(64) NOT NULL,
		created_at DATETIME NOT NULL,
		last_used_at DATETIME DEFAULT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY client_id (client_id),
		KEY ip_hash (ip_hash)
	) {$collate};"
	);

	dbDelta(
		'CREATE TABLE ' . table( 'codes' ) . " (
		code_hash CHAR(64) NOT NULL,
		client_id VARCHAR(64) NOT NULL,
		user_id BIGINT UNSIGNED NOT NULL,
		redirect_uri TEXT NOT NULL,
		code_challenge VARCHAR(128) NOT NULL,
		scope VARCHAR(191) NOT NULL,
		resource TEXT NOT NULL,
		expires_at DATETIME NOT NULL,
		used TINYINT(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (code_hash),
		KEY expires_at (expires_at)
	) {$collate};"
	);

	dbDelta(
		'CREATE TABLE ' . table( 'tokens' ) . " (
		token_hash CHAR(64) NOT NULL,
		token_type VARCHAR(8) NOT NULL,
		pair_hash CHAR(64) NOT NULL DEFAULT '',
		client_id VARCHAR(64) NOT NULL,
		user_id BIGINT UNSIGNED NOT NULL,
		scope VARCHAR(191) NOT NULL,
		resource TEXT NOT NULL,
		created_at DATETIME NOT NULL,
		expires_at DATETIME NOT NULL,
		last_used_at DATETIME DEFAULT NULL,
		revoked TINYINT(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (token_hash),
		KEY pair_hash (pair_hash),
		KEY user_client (user_id, client_id),
		KEY expires_at (expires_at)
	) {$collate};"
	);

	update_option( SCHEMA_OPTION, SCHEMA_VERSION, false );
	schedule();
}

/**
 * Whether the tables exist.
 */
function installed(): bool {
	return false !== get_option( SCHEMA_OPTION );
}

/**
 * Schedule the daily cleanup.
 */
function schedule(): void {
	if ( ! wp_next_scheduled( GC_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', GC_HOOK );
	}
}

/**
 * Remove the daily cleanup.
 */
function unschedule(): void {
	wp_clear_scheduled_hook( GC_HOOK );
}

/**
 * Delete expired rows and registrations that never completed a sign-in.
 */
function gc(): void {
	global $wpdb;
	$cutoff = now( -DAY_IN_SECONDS );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . table( 'codes' ) . ' WHERE expires_at < %s', $cutoff ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . table( 'tokens' ) . ' WHERE expires_at < %s', $cutoff ) );
	// A registration that was never used within a day, or whose last grant has long expired, frees its slot.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM ' . table( 'clients' ) . ' WHERE (last_used_at IS NULL AND created_at < %s) OR (last_used_at IS NOT NULL AND last_used_at < %s)',
			$cutoff,
			now( -2 * REFRESH_TOKEN_TTL )
		)
	);
}
add_action( GC_HOOK, __NAMESPACE__ . '\gc' );

/* ---------------------------------------------------------------- Clients */

/**
 * Register a client.
 *
 * @param string   $name          Client name as given by the client.
 * @param string[] $redirect_uris Allowed redirect URIs.
 * @param string   $client_uri    Optional homepage of the client.
 * @param string   $ip            Registering IP address.
 * @return string The new client id.
 */
function create_client( string $name, array $redirect_uris, string $client_uri, string $ip ): string {
	global $wpdb;
	$client_id = bin2hex( random_bytes( 16 ) );
	$wpdb->insert(
		table( 'clients' ),
		array(
			'client_id'     => $client_id,
			'client_name'   => $name,
			'redirect_uris' => wp_json_encode( array_values( $redirect_uris ) ),
			'client_uri'    => $client_uri,
			'ip_hash'       => hash_credential( $ip ),
			'created_at'    => now(),
		)
	);
	return $client_id;
}

/**
 * Load a client.
 *
 * @return array{client_id:string,client_name:string,redirect_uris:string[],client_uri:string,created_at:string,last_used_at:?string}|null
 */
function get_client( string $client_id ): ?array {
	global $wpdb;
	if ( '' === $client_id || strlen( $client_id ) > 64 ) {
		return null;
	}
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . table( 'clients' ) . ' WHERE client_id = %s', $client_id ), ARRAY_A );
	if ( ! $row ) {
		return null;
	}
	$uris                 = json_decode( (string) $row['redirect_uris'], true );
	$row['redirect_uris'] = is_array( $uris ) ? array_values( array_filter( $uris, 'is_string' ) ) : array();
	return $row;
}

/**
 * Record that a client completed a token exchange.
 */
function touch_client( string $client_id ): void {
	global $wpdb;
	$wpdb->update( table( 'clients' ), array( 'last_used_at' => now() ), array( 'client_id' => $client_id ) );
}

/**
 * How many clients an address registered.
 */
function client_count_for_ip( string $ip ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . table( 'clients' ) . ' WHERE ip_hash = %s', hash_credential( $ip ) ) );
}

/**
 * Delete a client and everything issued to it.
 */
function delete_client( string $client_id ): void {
	global $wpdb;
	$wpdb->delete( table( 'tokens' ), array( 'client_id' => $client_id ) );
	$wpdb->delete( table( 'codes' ), array( 'client_id' => $client_id ) );
	$wpdb->delete( table( 'clients' ), array( 'client_id' => $client_id ) );
}

/* ---------------------------------------------------------- Authorization codes */

/**
 * Issue an authorization code.
 *
 * @return string The raw code to hand to the client.
 */
function create_code( string $client_id, int $user_id, string $redirect_uri, string $code_challenge, string $scope, string $resource ): string {
	global $wpdb;
	$code = random_credential();
	$wpdb->insert(
		table( 'codes' ),
		array(
			'code_hash'      => hash_credential( $code ),
			'client_id'      => $client_id,
			'user_id'        => $user_id,
			'redirect_uri'   => $redirect_uri,
			'code_challenge' => $code_challenge,
			'scope'          => $scope,
			'resource'       => $resource,
			'expires_at'     => now( CODE_TTL ),
		)
	);
	return $code;
}

/**
 * Redeem an authorization code exactly once.
 *
 * The row is claimed with an atomic UPDATE before it is read, so a code that is
 * presented twice (or concurrently) is redeemed at most once. A replayed code
 * revokes everything issued from it, per RFC 6749 §4.1.2.
 *
 * @return array|null The code row, or null when it is unknown, expired or already used.
 */
function consume_code( string $code ): ?array {
	global $wpdb;
	$hash = hash_credential( $code );
	$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . table( 'codes' ) . ' SET used = 1 WHERE code_hash = %s AND used = 0 AND expires_at > %s', $hash, now() ) );
	if ( 1 !== $claimed ) {
		$replayed = $wpdb->get_row( $wpdb->prepare( 'SELECT client_id, user_id FROM ' . table( 'codes' ) . ' WHERE code_hash = %s AND used = 1', $hash ), ARRAY_A );
		if ( $replayed ) {
			revoke_grant( (string) $replayed['client_id'], (int) $replayed['user_id'] );
		}
		return null;
	}
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . table( 'codes' ) . ' WHERE code_hash = %s', $hash ), ARRAY_A );
	return $row ? $row : null;
}

/* ------------------------------------------------------------------ Tokens */

/**
 * Issue an access token and its refresh token.
 *
 * @return array{access_token:string,refresh_token:string,expires_in:int}
 */
function issue_tokens( string $client_id, int $user_id, string $scope, string $resource ): array {
	global $wpdb;
	$access  = random_credential();
	$refresh = random_credential();
	$base    = array(
		'client_id'  => $client_id,
		'user_id'    => $user_id,
		'scope'      => $scope,
		'resource'   => $resource,
		'created_at' => now(),
	);
	$wpdb->insert(
		table( 'tokens' ),
		$base + array(
			'token_hash' => hash_credential( $access ),
			'token_type' => 'access',
			'expires_at' => now( ACCESS_TOKEN_TTL ),
		)
	);
	$wpdb->insert(
		table( 'tokens' ),
		$base + array(
			'token_hash' => hash_credential( $refresh ),
			'token_type' => 'refresh',
			'pair_hash'  => hash_credential( $access ),
			'expires_at' => now( REFRESH_TOKEN_TTL ),
		)
	);
	return array(
		'access_token'  => $access,
		'refresh_token' => $refresh,
		'expires_in'    => ACCESS_TOKEN_TTL,
	);
}

/**
 * Find a live token of a type.
 *
 * @return array|null The row, or null when unknown, expired or revoked.
 */
function find_token( string $raw, string $type ): ?array {
	global $wpdb;
	if ( '' === $raw || strlen( $raw ) > 255 ) {
		return null;
	}
	$row = $wpdb->get_row(
		$wpdb->prepare( 'SELECT * FROM ' . table( 'tokens' ) . ' WHERE token_hash = %s AND token_type = %s AND revoked = 0 AND expires_at > %s', hash_credential( $raw ), $type, now() ),
		ARRAY_A
	);
	return $row ? $row : null;
}

/**
 * Note that an access token was used, at most once a minute.
 */
function touch_token( array $token ): void {
	global $wpdb;
	if ( ! empty( $token['last_used_at'] ) && strtotime( $token['last_used_at'] . ' UTC' ) > time() - MINUTE_IN_SECONDS ) {
		return;
	}
	$wpdb->update( table( 'tokens' ), array( 'last_used_at' => now() ), array( 'token_hash' => $token['token_hash'] ) );
}

/**
 * Claim a refresh token exactly once (rotation), revoking the access token it
 * was paired with.
 *
 * @return array|null The refresh token row, or null when it cannot be used.
 */
function consume_refresh_token( string $raw ): ?array {
	global $wpdb;
	$row = find_token( $raw, 'refresh' );
	if ( ! $row ) {
		return null;
	}
	$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . table( 'tokens' ) . ' SET revoked = 1 WHERE token_hash = %s AND revoked = 0', $row['token_hash'] ) );
	if ( 1 !== $claimed ) {
		return null;
	}
	$wpdb->update( table( 'tokens' ), array( 'revoked' => 1 ), array( 'token_hash' => $row['pair_hash'] ) );
	return $row;
}

/**
 * Revoke a token and its partner (RFC 7009), whichever of the pair was given.
 * Silently ignores unknown tokens, as the RFC requires.
 *
 * @param string $raw       The token.
 * @param string $client_id The client asking; a token owned by another client is left alone.
 */
function revoke_token( string $raw, string $client_id ): void {
	global $wpdb;
	if ( '' === $raw || strlen( $raw ) > 255 ) {
		return;
	}
	$hash = hash_credential( $raw );
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT token_hash, token_type, pair_hash, client_id FROM ' . table( 'tokens' ) . ' WHERE token_hash = %s', $hash ), ARRAY_A );
	if ( ! $row || ( '' !== $client_id && ! hash_equals( (string) $row['client_id'], $client_id ) ) ) {
		return;
	}
	$wpdb->update( table( 'tokens' ), array( 'revoked' => 1 ), array( 'token_hash' => $hash ) );
	if ( 'refresh' === $row['token_type'] ) {
		$wpdb->update( table( 'tokens' ), array( 'revoked' => 1 ), array( 'token_hash' => $row['pair_hash'] ) );
	} else {
		$wpdb->update( table( 'tokens' ), array( 'revoked' => 1 ), array( 'pair_hash' => $hash ) );
	}
}

/**
 * Revoke everything a user granted to a client.
 */
function revoke_grant( string $client_id, int $user_id ): void {
	global $wpdb;
	$wpdb->update(
		table( 'tokens' ),
		array( 'revoked' => 1 ),
		array(
			'client_id' => $client_id,
			'user_id'   => $user_id,
		)
	);
}

/**
 * The live connections of a user (or of everyone when $user_id is 0), one row
 * per client, keyed off the refresh token because that is the real lifetime of
 * a connection: access tokens expire hourly and are silently renewed.
 *
 * @return array<int, array{client_id:string,client_name:string,client_uri:string,user_id:int,resource:string,expires_at:string,last_used_at:?string,created_at:string}>
 */
function list_connections( int $user_id = 0 ): array {
	global $wpdb;
	$where = $user_id > 0 ? $wpdb->prepare( 'AND t.user_id = %d', $user_id ) : '';
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT c.client_id, c.client_name, c.client_uri, t.user_id, t.resource, MAX(t.expires_at) AS expires_at, MAX(a.last_used_at) AS last_used_at, MIN(t.created_at) AS created_at
			FROM ' . table( 'tokens' ) . ' t
			JOIN ' . table( 'clients' ) . ' c ON c.client_id = t.client_id
			LEFT JOIN ' . table( 'tokens' ) . " a ON a.token_hash = t.pair_hash
			WHERE t.token_type = 'refresh' AND t.revoked = 0 AND t.expires_at > %s {$where}
			GROUP BY c.client_id, c.client_name, c.client_uri, t.user_id, t.resource
			ORDER BY expires_at DESC",
			now()
		),
		ARRAY_A
	);
	return is_array( $rows ) ? $rows : array();
}
