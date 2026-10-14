<?php
/**
 * Tools → MCP Connect: how to connect each AI client, and the connections a
 * user has granted.
 *
 * @package mcp-connect
 */

namespace MCP_OAuth\Admin;

use MCP_OAuth\Abilities;
use MCP_OAuth\Clients;
use MCP_OAuth\Servers;
use MCP_OAuth\Storage;

defined( 'ABSPATH' ) || exit;

const PAGE_SLUG = 'mcp-connect';

/**
 * Hook the page and notices.
 */
function register(): void {
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );
	add_action( 'admin_menu', __NAMESPACE__ . '\register_page' );
	add_action( 'admin_notices', __NAMESPACE__ . '\dependency_notice' );
	add_filter( 'plugin_action_links_' . plugin_basename( MCP_OAUTH_FILE ), __NAMESPACE__ . '\action_links' );
}

/**
 * The page URL.
 */
function page_url( array $args = array() ): string {
	return add_query_arg( $args, admin_url( 'tools.php?page=' . PAGE_SLUG ) );
}

/**
 * "Connect" link on the plugins screen.
 */
function action_links( array $links ): array {
	array_unshift( $links, '<a href="' . esc_url( page_url() ) . '">' . esc_html__( 'Connect', 'mcp-connect' ) . '</a>' );
	return $links;
}

/**
 * Register the Tools page. Every user who may authorize a client can see and
 * revoke their own connections there.
 */
function register_page(): void {
	$hook = add_management_page( __( 'MCP Connect + WebMCP', 'mcp-connect' ), __( 'MCP Connect + WebMCP', 'mcp-connect' ), \MCP_OAuth\authorize_capability(), PAGE_SLUG, __NAMESPACE__ . '\render' );
	if ( $hook ) {
		add_action( 'load-' . $hook, __NAMESPACE__ . '\handle_actions' );
	}
}

/**
 * Warn when the bundled MCP Adapter did not load.
 */
function dependency_notice(): void {
	if ( \MCP_OAuth\adapter_available() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' . wp_kses_post( __( '<strong>MCP Connect</strong> could not load the <a href="https://github.com/WordPress/mcp-adapter">MCP Adapter</a> it bundles. Reinstall the plugin, or run <code>composer install</code> if this is a source checkout.', 'mcp-connect' ) ) . '</p></div>';
}

/**
 * Revoke / delete actions, before any output.
 */
function handle_actions(): void {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['mcp_oauth_action'] ) ) {
		return;
	}
	check_admin_referer( 'mcp_oauth_manage' );
	$action    = sanitize_key( wp_unslash( $_POST['mcp_oauth_action'] ) );
	$client_id = isset( $_POST['client_id'] ) ? sanitize_key( wp_unslash( $_POST['client_id'] ) ) : '';
	$user_id   = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : get_current_user_id();

	if ( get_current_user_id() !== $user_id && ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You may only revoke your own connections.', 'mcp-connect' ), '', array( 'response' => 403 ) );
	}
	if ( 'revoke' === $action && '' !== $client_id ) {
		Storage\revoke_grant( $client_id, $user_id );
	} elseif ( 'delete_client' === $action && '' !== $client_id && current_user_can( 'manage_options' ) ) {
		Storage\delete_client( $client_id );
	}
	wp_safe_redirect(
		page_url(
			array(
				'revoked' => 1,
				'tab'     => 'connections',
			)
		)
	);
	exit;
}

/**
 * Format a UTC database timestamp in the site's locale.
 */
function format_time( ?string $value ): string {
	if ( ! $value ) {
		return '—';
	}
	$ts = strtotime( $value . ' UTC' );
	return $ts ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : $value;
}

/**
 * The page.
 */
function render(): void {
	$servers  = Servers\all();
	$selected = isset( $_GET['server'] ) ? sanitize_text_field( wp_unslash( $_GET['server'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$server   = $servers[ $selected ] ?? Servers\primary();
	$url      = $server ? Servers\resource( $server ) : '';
	$is_admin = current_user_can( 'manage_options' );
	$tabs     = array(
		'connect'     => __( 'Connect', 'mcp-connect' ),
		'abilities'   => __( 'Abilities', 'mcp-connect' ),
		'connections' => __( 'Connections', 'mcp-connect' ),
	);
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connect'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'tools' === $tab ) {
		$tab = 'abilities';
	}
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = 'connect';
	}
	?>
	<div class="wrap mcp-oauth">
		<h1><?php esc_html_e( 'MCP Connect + WebMCP', 'mcp-connect' ); ?></h1>
		<p class="description" style="max-width:52em"><?php esc_html_e( 'AI assistants can connect through MCP with OAuth, or use WebMCP in a supported browser. Revoke OAuth clients under Connections; manage ability visibility under Abilities. Both use WordPress ability permissions.', 'mcp-connect' ); ?></p>

			<?php if ( 'connect' === $tab && \MCP_OAuth\is_playground() ) : ?>
			<div class="notice notice-info inline mcp-oauth-playground-notice"><p>
				<?php
				printf(
					/* translators: 1: link to the guide on connecting AI agents to Playground, 2: link to the Playground Query API documentation */
					esc_html__( 'This site runs in WordPress Playground, which lives in your browser and cannot be reached by remote MCP clients. WebMCP works within a supported browser. To use remote MCP with Playground, see %1$s and the %2$s.', 'mcp-connect' ),
					'<a href="https://make.wordpress.org/playground/2026/03/17/connect-ai-coding-agents-to-wordpress-playground-with-mcp/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Connect AI coding agents to WordPress Playground with MCP', 'mcp-connect' ) . '</a>',
					'<a href="https://developer.wordpress.org/playground/developers/apis/query-api/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Playground Query API documentation', 'mcp-connect' ) . '</a>'
				);
				?>
			</p></div>
		<?php endif; ?>

		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $id => $label ) : ?>
				<a class="nav-tab<?php echo $tab === $id ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( page_url( array( 'tab' => $id ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php if ( ! empty( $_GET['revoked'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Access revoked.', 'mcp-connect' ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! \MCP_OAuth\adapter_available() ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'The MCP Adapter did not load, so there is no MCP server to connect to.', 'mcp-connect' ); ?></p></div>
		<?php elseif ( ! \MCP_OAuth\transport_allowed() ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'This site is served over plain HTTP. Sign-in tokens would travel unencrypted, so the OAuth endpoints are disabled until the site uses HTTPS.', 'mcp-connect' ); ?></p></div>
		<?php elseif ( ! $server ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'No MCP server is registered. The MCP Adapter’s default server is missing — check the mcp_adapter_create_default_server filter.', 'mcp-connect' ); ?></p></div>
		<?php endif; ?>

		<?php if ( 'connect' === $tab ) : ?>
			<?php if ( $server && \MCP_OAuth\transport_allowed() ) : ?>
				<?php render_connect( $server, $servers, $url, $is_admin ); ?>
			<?php else : ?>
				<?php render_browser_connection( $is_admin ); ?>
			<?php endif; ?>
		<?php elseif ( 'abilities' === $tab ) : ?>
			<?php render_abilities( $is_admin ); ?>
		<?php elseif ( 'connections' === $tab ) : ?>
			<?php render_connections( $is_admin ); ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * The connect section: endpoint, setup check, client catalog.
 *
 * @param \WP\MCP\Core\McpServer   $server   Selected server.
 * @param \WP\MCP\Core\McpServer[] $servers  All servers.
 * @param string                   $url      Endpoint URL.
 * @param bool                     $is_admin Whether the viewer is an administrator.
 */
function render_connect( $server, array $servers, string $url, bool $is_admin ): void {
	$cloud_ok = Clients\reachable_from_cloud();
	?>
	<?php if ( count( $servers ) > 1 ) : ?>
		<form method="get" style="margin:12px 0 0">
			<input type="hidden" name="page" value="<?php echo esc_attr( PAGE_SLUG ); ?>">
			<label for="mcp-oauth-server"><?php esc_html_e( 'MCP server:', 'mcp-connect' ); ?></label>
			<select name="server" id="mcp-oauth-server" onchange="this.form.submit()">
				<?php foreach ( $servers as $id => $candidate ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $candidate === $server ); ?>><?php echo esc_html( $candidate->get_server_name() ); ?></option>
				<?php endforeach; ?>
			</select>
		</form>
	<?php endif; ?>

	<p style="margin-top:16px">
		<?php esc_html_e( 'Choose how your AI assistant connects. Remote clients sign in through your browser; browser assistants can use WebMCP on this site.', 'mcp-connect' ); ?>
		<?php if ( $is_admin ) : ?>
			<?php
			printf(
				/* translators: %s: link to Site Health */
				esc_html__( '%s verifies that the endpoints are reachable.', 'mcp-connect' ),
				'<a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Site Health', 'mcp-connect' ) . '</a>'
			);
			?>
		<?php endif; ?>
		<?php if ( ! $cloud_ok ) : ?>
			<br><strong><?php esc_html_e( 'This site is not reachable from the internet, so Claude.ai and ChatGPT cannot connect to it; desktop and terminal clients can.', 'mcp-connect' ); ?></strong>
		<?php endif; ?>
	</p>
	<div class="mcp-oauth-tabs" role="tablist">
		<?php foreach ( Clients\catalog( $url ) as $id => $client ) : ?>
			<button type="button" role="tab" class="mcp-oauth-tab" data-target="mcp-oauth-client-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $client['name'] ); ?></button>
		<?php endforeach; ?>
		<button type="button" role="tab" class="mcp-oauth-tab" data-target="mcp-oauth-client-browser"><?php esc_html_e( 'Browser (WebMCP)', 'mcp-connect' ); ?></button>
	</div>
	<?php foreach ( Clients\catalog( $url ) as $id => $client ) : ?>
		<div class="mcp-oauth-client card" id="mcp-oauth-client-<?php echo esc_attr( $id ); ?>" hidden>
			<h3><?php echo esc_html( $client['name'] ); ?></h3>
			<?php if ( ! empty( $client['cloud'] ) && ! $cloud_ok ) : ?>
				<p class="notice notice-warning inline" style="padding:8px 12px">
					<?php
					printf(
						/* translators: %s: client name */
						esc_html__( '%s connects from its own servers and cannot reach a site that is only available locally.', 'mcp-connect' ),
						esc_html( $client['name'] )
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $client['link'] ) ) : ?>
				<p class="mcp-oauth-oneclick">
					<a class="button button-primary button-hero" href="<?php echo esc_url( $client['link']['url'], array( 'https', 'cursor' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $client['link']['label'] ); ?></a>
					<?php if ( ! empty( $client['link']['description'] ) ) : ?>
						<span class="description"><?php echo esc_html( $client['link']['description'] ); ?></span>
					<?php endif; ?>
				</p>
				<?php if ( ! empty( $client['steps'] ) || ! empty( $client['snippet'] ) ) : ?>
					<h4 class="mcp-oauth-manual"><?php esc_html_e( 'Or set it up by hand', 'mcp-connect' ); ?></h4>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( ! empty( $client['steps'] ) ) : ?>
				<ol>
					<?php foreach ( $client['steps'] as $step ) : ?>
						<li><?php echo esc_html( $step ); ?></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
			<?php if ( ! empty( $client['hint'] ) ) : ?>
				<p><?php echo wp_kses( $client['hint'], array( 'code' => array() ) ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $client['steps'] ) || ! empty( $client['url'] ) ) : ?>
				<div class="mcp-oauth-endpoint">
					<code<?php echo ! empty( $client['url'] ) ? ' id="mcp-oauth-url"' : ''; ?>><?php echo esc_html( $url ); ?></code>
					<button type="button" class="button button-small mcp-oauth-copy" data-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Copy', 'mcp-connect' ); ?></button>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $client['command'] ) ) : ?>
				<div class="mcp-oauth-snippet">
					<pre class="is-shell"><code><?php echo esc_html( $client['command'] ); ?></code></pre>
					<button type="button" class="button button-small mcp-oauth-copy" data-copy="<?php echo esc_attr( $client['command'] ); ?>"><?php esc_html_e( 'Copy', 'mcp-connect' ); ?></button>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $client['snippet_intro'] ) ) : ?>
				<p><?php echo esc_html( $client['snippet_intro'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $client['snippet'] ) ) : ?>
				<div class="mcp-oauth-snippet">
					<pre><code><?php echo esc_html( $client['snippet'] ); ?></code></pre>
					<button type="button" class="button button-small mcp-oauth-copy" data-copy="<?php echo esc_attr( $client['snippet'] ); ?>"><?php esc_html_e( 'Copy', 'mcp-connect' ); ?></button>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $client['paths'] ) ) : ?>
				<ul class="mcp-oauth-paths">
					<?php foreach ( $client['paths'] as $label => $path ) : ?>
						<li><strong><?php echo esc_html( $label ); ?>:</strong> <code><?php echo esc_html( $path ); ?></code></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $client['bridge'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'Uses the mcp-remote bridge (needs Node.js). It opens the browser for sign-in the first time the client starts the server.', 'mcp-connect' ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $client['note'] ) ) : ?>
				<p class="description"><?php echo esc_html( $client['note'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $client['docs_url'] ) ) : ?>
				<p><a href="<?php echo esc_url( $client['docs_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Official setup documentation', 'mcp-connect' ); ?></a></p>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
	<div class="mcp-oauth-client card" id="mcp-oauth-client-browser" hidden>
		<?php render_browser_connection( $is_admin ); ?>
	</div>
	<?php
}

/**
 * Browser connection instructions and the site-wide bridge setting.
 *
 * @param bool $is_admin Whether the viewer can change settings.
 */
function render_browser_connection( bool $is_admin ): void {
	$settings = \MCP_OAuth\WebMCP\Plugin::instance()->settings;
	?>
	<h3><?php esc_html_e( 'Browser (WebMCP)', 'mcp-connect' ); ?></h3>
	<p><?php esc_html_e( 'Open this site in a browser with WebMCP support. Its AI assistant can use the available abilities as the current visitor, without adding an MCP server URL.', 'mcp-connect' ); ?></p>
	<p><?php esc_html_e( 'Choose which abilities are available on the Abilities tab. Their WordPress permission checks apply to browser assistants too.', 'mcp-connect' ); ?></p>
	<?php if ( class_exists( '\WebMCP\Plugin', false ) ) : ?>
		<p><?php esc_html_e( 'The standalone WebMCP Abilities plugin currently provides the browser connection. Deactivate it to use the connection included here.', 'mcp-connect' ); ?></p>
	<?php endif; ?>
	<?php if ( ! \MCP_OAuth\WebMCP\Secure_Context::is_available() ) : ?>
		<p><?php esc_html_e( 'Browser connections require HTTPS or a local loopback address.', 'mcp-connect' ); ?></p>
	<?php endif; ?>
	<?php if ( $is_admin ) : ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'mcp_connect_webmcp' ); ?>
			<input type="hidden" name="wmcp_enabled" value="0">
			<p><label><input type="checkbox" name="wmcp_enabled" id="wmcp_enabled" value="1"<?php checked( $settings->is_enabled() ); ?>> <?php esc_html_e( 'Enable browser connections through WebMCP', 'mcp-connect' ); ?></label></p>
			<?php submit_button( __( 'Save browser setting', 'mcp-connect' ), 'secondary' ); ?>
		</form>
	<?php endif; ?>
	<?php
}

/**
 * The connections tables.
 */
function render_connections( bool $is_admin ): void {
	if ( ! Storage\installed() ) {
		return;
	}
	$mine = Storage\list_connections( get_current_user_id() );
	?>
	<h2><?php esc_html_e( 'Your connected clients', 'mcp-connect' ); ?></h2>
	<?php if ( ! $mine ) : ?>
		<p><?php esc_html_e( 'No AI client is connected to your account yet.', 'mcp-connect' ); ?></p>
	<?php else : ?>
		<?php connections_table( $mine, false ); ?>
	<?php endif; ?>

	<?php if ( $is_admin ) : ?>
		<?php $all = Storage\list_connections(); ?>
		<h2><?php esc_html_e( 'All connections on this site', 'mcp-connect' ); ?></h2>
		<?php if ( ! $all ) : ?>
			<p><?php esc_html_e( 'No AI client is connected to any account.', 'mcp-connect' ); ?></p>
		<?php else : ?>
			<?php connections_table( $all, true ); ?>
		<?php endif; ?>
	<?php endif; ?>
	<?php
}

/**
 * A table of connections.
 */
function connections_table( array $rows, bool $show_user ): void {
	// A token is bound to one MCP server; the column only says something when there are several.
	$show_server = count( Servers\all() ) > 1;
	?>
	<table class="widefat striped" style="max-width:1100px">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Client', 'mcp-connect' ); ?></th>
				<th><?php esc_html_e( 'Type', 'mcp-connect' ); ?></th>
				<?php if ( $show_user ) : ?>
					<th><?php esc_html_e( 'User', 'mcp-connect' ); ?></th>
				<?php endif; ?>
				<?php if ( $show_server ) : ?>
					<th><?php esc_html_e( 'MCP server', 'mcp-connect' ); ?></th>
				<?php endif; ?>
				<th><?php esc_html_e( 'Connected', 'mcp-connect' ); ?></th>
				<th><?php esc_html_e( 'Last used', 'mcp-connect' ); ?></th>
				<th><?php esc_html_e( 'Expires if unused', 'mcp-connect' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $rows as $row ) : ?>
			<?php
			$server = Servers\for_resource( (string) $row['resource'] );
			$user   = $show_user ? get_user_by( 'id', (int) $row['user_id'] ) : null;
			?>
			<tr>
				<td>
					<strong><?php echo esc_html( $row['client_name'] ); ?></strong>
					<?php if ( ! empty( $row['client_uri'] ) ) : ?>
						<br><a href="<?php echo esc_url( $row['client_uri'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( wp_parse_url( $row['client_uri'], PHP_URL_HOST ) ); ?></a>
					<?php endif; ?>
				</td>
				<td><?php esc_html_e( 'OAuth 2.1', 'mcp-connect' ); ?></td>
				<?php if ( $show_user ) : ?>
					<td><?php echo $user ? esc_html( $user->display_name ) : esc_html( '#' . (int) $row['user_id'] ); ?></td>
				<?php endif; ?>
				<?php if ( $show_server ) : ?>
					<td><?php echo $server ? esc_html( $server->get_server_name() ) : '<code>' . esc_html( $row['resource'] ) . '</code>'; ?></td>
				<?php endif; ?>
				<td><?php echo esc_html( format_time( $row['created_at'] ) ); ?></td>
				<td><?php echo esc_html( format_time( $row['last_used_at'] ) ); ?></td>
				<td><?php echo esc_html( format_time( $row['expires_at'] ) ); ?></td>
				<td>
					<form method="post">
						<?php wp_nonce_field( 'mcp_oauth_manage' ); ?>
						<input type="hidden" name="mcp_oauth_action" value="revoke">
						<input type="hidden" name="client_id" value="<?php echo esc_attr( $row['client_id'] ); ?>">
						<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $row['user_id'] ); ?>">
						<button type="submit" class="button button-small"><?php esc_html_e( 'Revoke', 'mcp-connect' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Configure shared ability visibility and optional MCP tool shortcuts.
 */
function render_abilities( bool $is_admin ): void {
	$rows = array_values(
		array_filter(
			Abilities\report(),
			static function ( array $row ): bool {
				return 0 !== strpos( $row['name'], 'mcp-adapter/' );
			}
		)
	);
	$visible = count( array_filter( array_column( $rows, 'visible' ) ) );
	$direct  = count( array_filter( array_column( $rows, 'direct' ) ) );
	?>
	<h2 id="abilities"><?php esc_html_e( 'Abilities', 'mcp-connect' ); ?></h2>
	<p><?php esc_html_e( 'Choose what AI assistants can use. Each ability always checks the user’s permissions.', 'mcp-connect' ); ?></p>
	<?php if ( ! $rows ) : ?>
		<p><?php esc_html_e( 'No abilities are registered.', 'mcp-connect' ); ?></p>
		<?php return; ?>
	<?php endif; ?>
	<div class="mcp-oauth-ability-toolbar">
		<label class="screen-reader-text" for="mcp-oauth-ability-search"><?php esc_html_e( 'Search abilities', 'mcp-connect' ); ?></label>
		<input type="search" id="mcp-oauth-ability-search" placeholder="<?php esc_attr_e( 'Search abilities…', 'mcp-connect' ); ?>">
		<label class="screen-reader-text" for="mcp-oauth-ability-filter"><?php esc_html_e( 'Filter abilities', 'mcp-connect' ); ?></label>
		<select id="mcp-oauth-ability-filter">
			<option value="all"><?php esc_html_e( 'All abilities', 'mcp-connect' ); ?></option>
			<option value="available"><?php esc_html_e( 'Available', 'mcp-connect' ); ?></option>
			<option value="hidden"><?php esc_html_e( 'Hidden', 'mcp-connect' ); ?></option>
		</select>
		<span class="description"><strong id="mcp-oauth-visible-count"><?php echo (int) $visible; ?></strong> <?php esc_html_e( 'available', 'mcp-connect' ); ?></span>
	</div>
	<div class="mcp-oauth-scroll">
	<table id="mcp-oauth-abilities-table" class="widefat striped" style="max-width:1100px">
		<thead>
			<tr>
				<th class="mcp-oauth-ability-column"><?php esc_html_e( 'Ability', 'mcp-connect' ); ?></th>
				<th><?php esc_html_e( 'What it does', 'mcp-connect' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $rows as $row ) : ?>
			<tr class="mcp-oauth-ability<?php echo $row['visible'] ? '' : ' is-hidden'; ?>" data-ability="<?php echo esc_attr( $row['name'] ); ?>" data-search="<?php echo esc_attr( strtolower( $row['label'] . ' ' . $row['name'] . ' ' . $row['description'] ) ); ?>">
				<td colspan="2">
					<div class="mcp-oauth-ability-summary">
					<div class="mcp-oauth-ability-heading">
					<?php $icon = $row['visible'] ? '<span class="dashicons dashicons-visibility" style="color:#00a32a"></span>' : '<span class="dashicons dashicons-hidden"></span>'; ?>
					<?php if ( $is_admin && ! $row['locked'] ) : ?>
						<button type="button" class="mcp-oauth-eye" data-ability="<?php echo esc_attr( $row['name'] ); ?>" data-visible="<?php echo $row['visible'] ? '1' : '0'; ?>" aria-pressed="<?php echo $row['visible'] ? 'true' : 'false'; ?>" title="<?php echo esc_attr( $row['visible'] ? __( 'Visible — click to hide', 'mcp-connect' ) : __( 'Hidden — click to show', 'mcp-connect' ) ); ?>"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></button>
					<?php else : ?>
						<span title="<?php echo esc_attr( $row['visible'] ? __( 'Visible', 'mcp-connect' ) : __( 'Hidden', 'mcp-connect' ) ); ?>"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></span>
					<?php endif; ?>
						<strong><?php echo esc_html( $row['label'] ); ?></strong>
					</div>
						<div class="mcp-oauth-ability-description"><?php echo esc_html( $row['description'] ); ?></div>
					</div>
					<details class="mcp-oauth-ability-details">
						<summary><?php esc_html_e( 'Details', 'mcp-connect' ); ?></summary>
						<p><code><?php echo esc_html( $row['name'] ); ?></code> · <?php echo esc_html( $row['type'] ); ?></p>
						<p class="mcp-oauth-reason<?php echo $row['override'] ? ' is-override' : ''; ?>"><?php echo esc_html( $row['reason'] ); ?></p>

					<?php if ( $row['tool_type'] ) : ?>
						<label><input type="checkbox" class="mcp-oauth-tool" data-ability="<?php echo esc_attr( $row['name'] ); ?>"<?php checked( $row['direct'] ); ?><?php disabled( ! $is_admin || ! $row['visible'] ); ?> title="<?php echo esc_attr( $row['visible'] ? __( 'Expose this ability as a tool of its own', 'mcp-connect' ) : __( 'Hidden abilities cannot be tools', 'mcp-connect' ) ); ?>"> <?php esc_html_e( 'Add to the MCP client’s tool list', 'mcp-connect' ); ?></label>
						<p class="description"><?php esc_html_e( 'MCP clients can already discover and use this ability. Enable this to give it its own entry in their tool list, so they can call it directly. Each added entry takes up space in the client’s tool list. WebMCP already lists available abilities individually.', 'mcp-connect' ); ?></p>
					<?php endif; ?>

					</details>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<p id="mcp-oauth-no-abilities" hidden role="status"><?php esc_html_e( 'No abilities match your search.', 'mcp-connect' ); ?></p>
	<details class="mcp-oauth-ability-help">
		<summary><?php esc_html_e( 'How access works', 'mcp-connect' ); ?></summary>
		<p><strong id="mcp-oauth-direct-count"><?php echo (int) $direct; ?></strong> <?php esc_html_e( 'direct MCP tools', 'mcp-connect' ); ?></p>
		<p><?php esc_html_e( 'The eye controls whether an ability is available through MCP and WebMCP. Both protocols use the same abilities and WordPress permissions. In Details, you can also add an ability to the MCP client’s tool list as a direct shortcut.', 'mcp-connect' ); ?></p>
	</details>
	<?php if ( \MCP_OAuth\adapter_available() ) : ?>
		<details class="mcp-oauth-ability-help" id="mcp-oauth-tool-inventory">
			<summary><?php esc_html_e( 'MCP client tool list', 'mcp-connect' ); ?></summary>
			<?php render_tools( Servers\all() ); ?>
		</details>
	<?php endif; ?>
	<?php
}

/**
 * What each MCP server actually hands to a connected client.
 *
 * The Abilities tab is the site's side of the arrangement; this is the adapter's,
 * read back from the servers after every filter has run.
 *
 * @param \WP\MCP\Core\McpServer[] $servers Registered servers.
 */
function render_tools( array $servers ): void {
	?>
	<h2 id="tools"><?php esc_html_e( 'Tools exposed to connected clients', 'mcp-connect' ); ?></h2>
	<p><?php esc_html_e( 'For troubleshooting: this is the MCP tool inventory advertised to connected clients. The adapter’s helper tools discover and execute available abilities; direct tools provide shortcuts to individual abilities.', 'mcp-connect' ); ?></p>
	<?php if ( ! $servers ) : ?>
		<p><?php esc_html_e( 'No MCP server is registered.', 'mcp-connect' ); ?></p>
		<?php return; ?>
	<?php endif; ?>
	<?php foreach ( $servers as $server ) : ?>
		<?php $rows = Servers\inventory( $server ); ?>
		<h3><?php echo esc_html( $server->get_server_name() ); ?></h3>
		<p class="description"><code><?php echo esc_html( Servers\resource( $server ) ); ?></code></p>
		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'This server exposes nothing at all.', 'mcp-connect' ); ?></p>
			<?php continue; ?>
		<?php endif; ?>
		<div class="mcp-oauth-scroll">
		<table class="widefat striped" style="max-width:1100px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'mcp-connect' ); ?></th>
					<th><?php esc_html_e( 'Type', 'mcp-connect' ); ?></th>
					<th><?php esc_html_e( 'Ability', 'mcp-connect' ); ?></th>
					<th><?php esc_html_e( 'Description', 'mcp-connect' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr data-tool="<?php echo esc_attr( $row['name'] ); ?>">
					<td><code><?php echo esc_html( $row['name'] ); ?></code></td>
					<td><?php echo esc_html( $row['type'] ); ?></td>
					<td><?php echo $row['ability'] ? '<code>' . esc_html( $row['ability'] ) . '</code>' : '—'; ?></td>
					<td><?php echo esc_html( $row['description'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	<?php endforeach; ?>
	<?php
}

/**
 * Enqueue assets only on the connection page.
 */
function enqueue_assets( string $hook ): void {
	if ( 'tools_page_' . PAGE_SLUG !== $hook ) {
		return;
	}
	wp_enqueue_style( 'mcp-connect-admin', plugins_url( 'assets/admin.css', MCP_OAUTH_FILE ), array(), MCP_OAUTH_VERSION );
	wp_enqueue_script( 'mcp-connect-admin', plugins_url( 'assets/admin.js', MCP_OAUTH_FILE ), array(), MCP_OAUTH_VERSION, true );
	wp_localize_script(
		'mcp-connect-admin',
		'mcpConnectAdmin',
		array(
			'copied'          => __( 'Copied', 'mcp-connect' ),
			'visibilityUrl'   => rest_url( \MCP_OAuth\REST_NAMESPACE . '/abilities/visibility' ),
			'visibleTitle'    => __( 'Visible — click to hide', 'mcp-connect' ),
			'hiddenTitle'     => __( 'Hidden — click to show', 'mcp-connect' ),
			'toolTitle'       => __( 'Expose this ability as a tool of its own', 'mcp-connect' ),
			'hiddenToolTitle' => __( 'Hidden abilities cannot be tools', 'mcp-connect' ),
			'visibilityError' => __( 'Could not change the visibility. Reload the page and try again.', 'mcp-connect' ),
			'toolUrl'         => rest_url( \MCP_OAuth\REST_NAMESPACE . '/abilities/tool' ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'toolError'       => __( 'Could not change the tools. Reload the page and try again.', 'mcp-connect' ),
		)
	);
}
