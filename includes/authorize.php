<?php
/**
 * Authorization endpoint with consent screen.
 *
 * Lives on wp-login.php (`?action=mcp-oauth-authorize`) instead of a REST route:
 * a visitor who is not signed in gets the ordinary login form and comes back
 * here afterwards, and the consent screen is drawn with the login page's own
 * chrome. Cookie authentication works even on sites that disable it for the
 * REST API. Only PKCE (S256) authorization-code requests are accepted.
 *
 * @package mcp-connect
 */

namespace MCP_OAuth\Authorize;

use MCP_OAuth\Register;
use MCP_OAuth\Servers;
use MCP_OAuth\Storage;

defined( 'ABSPATH' ) || exit;

const ACTION         = 'mcp-oauth-authorize';
const PENDING_PREFIX = 'mcp_oauth_pending_';
const PENDING_TTL    = 10 * MINUTE_IN_SECONDS;

/**
 * Hook the login action.
 */
function register(): void {
	repair_folded_request();
	add_action( 'login_form_' . ACTION, __NAMESPACE__ . '\handle' );
}

/**
 * The advertised authorization endpoint.
 */
function endpoint_url(): string {
	return add_query_arg( 'action', ACTION, wp_login_url() );
}

/**
 * Some clients append "?param=…" to the advertised endpoint although it already
 * carries "?action=…", which folds their parameters into the action name. Unfold
 * them before wp-login.php reads the action.
 */
function repair_folded_request(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$action = $_REQUEST['action'] ?? '';
	if ( 'wp-login.php' !== ( $GLOBALS['pagenow'] ?? '' ) || ! is_string( $action ) || 0 !== strpos( $action, ACTION . '?' ) ) {
		return;
	}
	list( $slug, $query ) = explode( '?', $action, 2 );
	$_GET['action']       = $slug;
	$_REQUEST['action']   = $slug;
	parse_str( $query, $recovered );
	foreach ( $recovered as $key => $value ) {
		if ( ! isset( $_GET[ $key ] ) ) {
			$_GET[ $key ]     = $value;
			$_REQUEST[ $key ] = $value;
		}
	}
	foreach ( array( 'REQUEST_URI', 'QUERY_STRING' ) as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) ) {
			$_SERVER[ $key ] = str_replace( ACTION . '?', ACTION . '&', $_SERVER[ $key ] );
		}
	}
	// phpcs:enable
}

/**
 * The full URL of the current authorization request, to come back to after
 * signing in or switching accounts.
 */
function request_url(): string {
	$params = array();
	foreach ( wp_unslash( $_GET ) as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is re-encoded below.
		if ( is_string( $value ) ) {
			$params[ sanitize_key( $key ) ] = $value;
		}
	}
	return site_url( 'wp-login.php?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) );
}

/**
 * A sanitized query parameter. The request arrives cross-site from the client
 * without a nonce (by design); it is validated by the registered redirect URI,
 * PKCE, and the nonce on the consent form.
 */
function param( string $key ): string {
	$raw = $_GET[ $key ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return is_string( $raw ) ? trim( sanitize_text_field( wp_unslash( $raw ) ) ) : '';
}

/**
 * Whether a redirect URI matches one the client registered. Loopback URIs may
 * use any port (RFC 8252 §7.3).
 */
function redirect_uri_registered( string $uri, array $registered ): bool {
	if ( in_array( $uri, $registered, true ) ) {
		return true;
	}
	$p = wp_parse_url( $uri );
	if ( ! is_array( $p ) || empty( $p['host'] ) || ! Register\is_loopback( $p['host'] ) ) {
		return false;
	}
	foreach ( $registered as $candidate ) {
		$c = wp_parse_url( $candidate );
		if ( is_array( $c ) && ! empty( $c['host'] ) && Register\is_loopback( $c['host'] )
			&& ( $c['scheme'] ?? '' ) === ( $p['scheme'] ?? '' ) && ( $c['path'] ?? '' ) === ( $p['path'] ?? '' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Send the browser back to the client with an error.
 */
function redirect_error( string $redirect_uri, string $state, string $error, string $description ): void {
	$args = array(
		'error'             => $error,
		'error_description' => $description,
	);
	if ( '' !== $state ) {
		$args['state'] = $state;
	}
	wp_redirect( add_query_arg( $args, $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The client's registered URI, possibly a custom scheme.
	exit;
}

/**
 * Stop with an error the client can never receive (no trustworthy redirect URI).
 */
function fail( string $message, int $status = 400 ): void {
	wp_die( esc_html( $message ), esc_html__( 'Authorization failed', 'mcp-oauth' ), array( 'response' => (int) $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- An integer.
}

/**
 * Entry point from wp-login.php: sign the visitor in first if needed, then
 * handle the consent form or validate a new request and draw the consent screen.
 */
function handle(): void {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( request_url() ) );
		exit;
	}
	if ( ! current_user_can( \MCP_OAuth\authorize_capability() ) ) {
		fail( __( 'Your account is not allowed to connect AI clients to this site.', 'mcp-oauth' ), 403 );
	}
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		handle_consent();
		return;
	}
	$pending = handle_request();
	render( $pending );
	exit;
}

/**
 * Validate an incoming authorization request and park it for the consent form.
 *
 * @return array{token:string,client:array,server:\WP\MCP\Core\McpServer}
 */
function handle_request(): array {
	$client_id = param( 'client_id' );
	$client    = Storage\get_client( $client_id );
	if ( ! $client ) {
		fail( __( 'Unknown client. The AI client has to register with this site before it can sign in.', 'mcp-oauth' ) );
	}
	$redirect_uri = param( 'redirect_uri' );
	if ( '' === $redirect_uri || ! redirect_uri_registered( $redirect_uri, $client['redirect_uris'] ) ) {
		fail( __( 'The redirect URI is not registered for this client.', 'mcp-oauth' ) );
	}
	$state = param( 'state' );

	if ( 'code' !== param( 'response_type' ) ) {
		redirect_error( $redirect_uri, $state, 'unsupported_response_type', 'Only response_type=code is supported.' );
	}
	$challenge = param( 'code_challenge' );
	if ( '' === $challenge || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $challenge ) || 'S256' !== param( 'code_challenge_method' ) ) {
		redirect_error( $redirect_uri, $state, 'invalid_request', 'PKCE with code_challenge_method=S256 is required.' );
	}

	$scope = param( 'scope' );
	foreach ( preg_split( '/\s+/', $scope, -1, PREG_SPLIT_NO_EMPTY ) as $requested ) {
		if ( \MCP_OAuth\SCOPE !== $requested ) {
			redirect_error( $redirect_uri, $state, 'invalid_scope', 'Unknown scope: ' . $requested );
		}
	}

	$resource = param( 'resource' );
	$server   = '' === $resource ? Servers\primary() : Servers\for_resource( $resource );
	if ( ! $server ) {
		redirect_error( $redirect_uri, $state, 'invalid_target', '' === $resource ? 'No MCP server is registered on this site.' : 'The requested resource is not an MCP server on this site.' );
	}

	$token = bin2hex( random_bytes( 16 ) );
	set_transient(
		PENDING_PREFIX . $token,
		array(
			'client_id'      => $client['client_id'],
			'redirect_uri'   => $redirect_uri,
			'code_challenge' => $challenge,
			'state'          => $state,
			'resource'       => Servers\resource( $server ),
			'server_name'    => $server->get_server_name(),
			'user_id'        => get_current_user_id(),
		),
		PENDING_TTL
	);
	return array(
		'token'  => $token,
		'client' => $client,
		'server' => $server,
	);
}

/**
 * Handle the Authorize / Deny form.
 */
function handle_consent(): void {
	$token = isset( $_POST['pending'] ) ? sanitize_key( wp_unslash( $_POST['pending'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked next.
	check_admin_referer( 'mcp_oauth_consent_' . $token );

	$pending = get_transient( PENDING_PREFIX . $token );
	delete_transient( PENDING_PREFIX . $token );
	if ( ! is_array( $pending ) || get_current_user_id() !== (int) $pending['user_id'] ) {
		fail( __( 'This authorization request has expired. Start again from your AI client.', 'mcp-oauth' ) );
	}
	if ( empty( $_POST['approve'] ) ) {
		redirect_error( $pending['redirect_uri'], $pending['state'], 'access_denied', 'The user denied the request.' );
	}
	if ( ! Storage\get_client( $pending['client_id'] ) ) {
		fail( __( 'The client is no longer registered.', 'mcp-oauth' ) );
	}

	$code = Storage\create_code( $pending['client_id'], get_current_user_id(), $pending['redirect_uri'], $pending['code_challenge'], \MCP_OAuth\SCOPE, $pending['resource'] );
	$args = array( 'code' => $code );
	if ( '' !== $pending['state'] ) {
		$args['state'] = $pending['state'];
	}
	wp_redirect( add_query_arg( $args, $pending['redirect_uri'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The client's registered URI, possibly a custom scheme.
	exit;
}

/**
 * Human-readable destination of a redirect URI.
 */
function destination_label( string $uri ): string {
	$p = wp_parse_url( $uri );
	if ( ! is_array( $p ) || empty( $p['scheme'] ) ) {
		return $uri;
	}
	return empty( $p['host'] ) ? $p['scheme'] . ':' : $p['scheme'] . '://' . $p['host'];
}

/**
 * The consent screen, drawn with the login page's chrome.
 *
 * @param array{token:string,client:array,server:\WP\MCP\Core\McpServer} $pending The parked request.
 */
function render( array $pending ): void {
	$client = $pending['client'];
	$server = $pending['server'];
	$user   = wp_get_current_user();

	login_header( __( 'Connect an AI client', 'mcp-oauth' ) );
	?>
	<style>
		#login { width: 400px; }
		.mcp-oauth-consent h2 { font-size: 18px; font-weight: 600; margin: 0 0 12px; }
		.mcp-oauth-consent p { margin: 0 0 14px; }
		.mcp-oauth-consent dl { margin: 0 0 16px; }
		.mcp-oauth-consent dt { font-weight: 600; margin-top: 8px; }
		.mcp-oauth-consent dd { margin: 2px 0 0; word-break: break-all; }
		.mcp-oauth-consent .description { color: #646970; font-size: 13px; }
		.mcp-oauth-consent .submit { display: flex; gap: 8px; margin-top: 16px; }
		.mcp-oauth-consent .submit .button { float: none; }
	</style>
	<form class="mcp-oauth-consent" method="post" action="<?php echo esc_url( endpoint_url() ); ?>">
		<h2>
			<?php
			printf(
				/* translators: %s: client name */
				esc_html__( '%s wants to connect to this site', 'mcp-oauth' ),
				esc_html( $client['client_name'] )
			);
			?>
		</h2>
		<p>
			<?php
			printf(
				/* translators: 1: user display name, 2: user login, 3: MCP server name */
				esc_html__( 'It will act as %1$s (%2$s) through the MCP server “%3$s” and can do whatever your account may do there.', 'mcp-oauth' ),
				'<strong>' . esc_html( $user->display_name ) . '</strong>',
				esc_html( $user->user_login ),
				esc_html( $server->get_server_name() )
			);
			?>
		</p>
		<dl>
			<?php if ( ! empty( $client['client_uri'] ) ) : ?>
				<dt><?php esc_html_e( 'Client', 'mcp-oauth' ); ?></dt>
				<dd><a href="<?php echo esc_url( $client['client_uri'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $client['client_uri'] ); ?></a></dd>
			<?php endif; ?>
			<dt><?php esc_html_e( 'Returns to', 'mcp-oauth' ); ?></dt>
			<dd><code><?php echo esc_html( destination_label( param( 'redirect_uri' ) ) ); ?></code></dd>
			<dt><?php esc_html_e( 'MCP endpoint', 'mcp-oauth' ); ?></dt>
			<dd><code><?php echo esc_html( Servers\resource( $server ) ); ?></code></dd>
		</dl>
		<p class="description"><?php esc_html_e( 'The client name is provided by the client itself. Only connect clients you trust. You can revoke access at any time under Tools → MCP Connect.', 'mcp-oauth' ); ?></p>
		<?php wp_nonce_field( 'mcp_oauth_consent_' . $pending['token'] ); ?>
		<input type="hidden" name="pending" value="<?php echo esc_attr( $pending['token'] ); ?>">
		<p class="submit">
			<button type="submit" name="approve" value="1" class="button button-primary button-large"><?php esc_html_e( 'Connect', 'mcp-oauth' ); ?></button>
			<button type="submit" name="deny" value="1" class="button button-large"><?php esc_html_e( 'Deny', 'mcp-oauth' ); ?></button>
		</p>
	</form>
	<p id="nav">
		<?php
		printf(
			/* translators: %s: user login */
			esc_html__( 'Not %s?', 'mcp-oauth' ),
			esc_html( $user->user_login )
		);
		?>
		<a href="<?php echo esc_url( wp_logout_url( request_url() ) ); ?>"><?php esc_html_e( 'Log out', 'mcp-oauth' ); ?></a>
	</p>
	<?php
	login_footer();
}
