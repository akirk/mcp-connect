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
	array_unshift( $links, '<a href="' . esc_url( page_url() ) . '">' . esc_html__( 'Connect', 'mcp-oauth' ) . '</a>' );
	return $links;
}

/**
 * Register the Tools page. Every user who may authorize a client can see and
 * revoke their own connections there.
 */
function register_page(): void {
	$hook = add_management_page( __( 'MCP Connect + WebMCP', 'mcp-oauth' ), __( 'MCP Connect + WebMCP', 'mcp-oauth' ), \MCP_OAuth\authorize_capability(), PAGE_SLUG, __NAMESPACE__ . '\render' );
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
	echo '<div class="notice notice-error"><p>' . wp_kses_post( __( '<strong>MCP Connect</strong> could not load the <a href="https://github.com/WordPress/mcp-adapter">MCP Adapter</a> it bundles. Reinstall the plugin, or run <code>composer install</code> if this is a source checkout.', 'mcp-oauth' ) ) . '</p></div>';
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
		wp_die( esc_html__( 'You may only revoke your own connections.', 'mcp-oauth' ), '', array( 'response' => 403 ) );
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
		'connect'     => __( 'Connect', 'mcp-oauth' ),
		'abilities'   => __( 'Abilities', 'mcp-oauth' ),
		'connections' => __( 'Connections', 'mcp-oauth' ),
	);
	if ( $is_admin ) {
		$tabs['webmcp'] = __( 'WebMCP', 'mcp-oauth' );
	}
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connect'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'tools' === $tab ) {
		$tab = 'abilities';
	}
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = 'connect';
	}
	?>
	<div class="wrap mcp-oauth">
		<h1><?php esc_html_e( 'MCP Connect + WebMCP', 'mcp-oauth' ); ?></h1>
		<p class="description" style="max-width:52em"><?php esc_html_e( 'AI assistants can connect through MCP with OAuth, or use WebMCP in a supported browser. Revoke OAuth clients under Connections; manage ability visibility under Abilities. Both use WordPress ability permissions.', 'mcp-oauth' ); ?></p>

			<?php if ( 'connect' === $tab && \MCP_OAuth\is_playground() ) : ?>
			<div class="notice notice-info inline mcp-oauth-playground-notice"><p>
				<?php
				printf(
					/* translators: 1: link to the guide on connecting AI agents to Playground, 2: link to the Playground Query API documentation */
					esc_html__( 'This site runs in WordPress Playground, which lives in your browser and cannot be reached by remote MCP clients. WebMCP works within a supported browser. To use remote MCP with Playground, see %1$s and the %2$s.', 'mcp-oauth' ),
					'<a href="https://make.wordpress.org/playground/2026/03/17/connect-ai-coding-agents-to-wordpress-playground-with-mcp/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Connect AI coding agents to WordPress Playground with MCP', 'mcp-oauth' ) . '</a>',
					'<a href="https://developer.wordpress.org/playground/developers/apis/query-api/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Playground Query API documentation', 'mcp-oauth' ) . '</a>'
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
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Access revoked.', 'mcp-oauth' ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! \MCP_OAuth\adapter_available() ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'The MCP Adapter did not load, so there is no MCP server to connect to.', 'mcp-oauth' ); ?></p></div>
		<?php elseif ( ! \MCP_OAuth\transport_allowed() ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'This site is served over plain HTTP. Sign-in tokens would travel unencrypted, so the OAuth endpoints are disabled until the site uses HTTPS.', 'mcp-oauth' ); ?></p></div>
		<?php elseif ( ! $server ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'No MCP server is registered. The MCP Adapter’s default server is missing — check the mcp_adapter_create_default_server filter.', 'mcp-oauth' ); ?></p></div>
		<?php endif; ?>

		<?php if ( 'connect' === $tab ) : ?>
			<?php if ( $server && \MCP_OAuth\transport_allowed() ) : ?>
				<?php render_connect( $server, $servers, $url, $is_admin ); ?>
			<?php else : ?>
				<?php render_browser_connection( $is_admin ); ?>
			<?php endif; ?>
		<?php elseif ( 'abilities' === $tab ) : ?>
			<?php render_abilities( $is_admin ); ?>
		<?php elseif ( 'webmcp' === $tab && $is_admin ) : ?>
			<?php render_browser_connection( $is_admin ); ?>
		<?php elseif ( 'connections' === $tab ) : ?>
			<?php render_connections( $is_admin ); ?>
		<?php endif; ?>
	</div>
	<?php
	render_assets();
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
			<label for="mcp-oauth-server"><?php esc_html_e( 'MCP server:', 'mcp-oauth' ); ?></label>
			<select name="server" id="mcp-oauth-server" onchange="this.form.submit()">
				<?php foreach ( $servers as $id => $candidate ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $candidate === $server ); ?>><?php echo esc_html( $candidate->get_server_name() ); ?></option>
				<?php endforeach; ?>
			</select>
		</form>
	<?php endif; ?>

	<p style="margin-top:16px">
		<?php esc_html_e( 'Choose your AI client. Each signs in to this site through your browser.', 'mcp-oauth' ); ?>
		<?php if ( $is_admin ) : ?>
			<?php
			printf(
				/* translators: %s: link to Site Health */
				esc_html__( '%s verifies that the endpoints are reachable.', 'mcp-oauth' ),
				'<a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Site Health', 'mcp-oauth' ) . '</a>'
			);
			?>
		<?php endif; ?>
		<?php if ( ! $cloud_ok ) : ?>
			<br><strong><?php esc_html_e( 'This site is not reachable from the internet, so Claude.ai and ChatGPT cannot connect to it; desktop and terminal clients can.', 'mcp-oauth' ); ?></strong>
		<?php endif; ?>
	</p>
	<div class="mcp-oauth-tabs" role="tablist">
		<?php foreach ( Clients\catalog( $url ) as $id => $client ) : ?>
			<button type="button" role="tab" class="mcp-oauth-tab" data-target="mcp-oauth-client-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $client['name'] ); ?></button>
		<?php endforeach; ?>
	</div>
	<?php foreach ( Clients\catalog( $url ) as $id => $client ) : ?>
		<div class="mcp-oauth-client card" id="mcp-oauth-client-<?php echo esc_attr( $id ); ?>" hidden>
			<h3><?php echo esc_html( $client['name'] ); ?></h3>
			<?php if ( ! empty( $client['cloud'] ) && ! $cloud_ok ) : ?>
				<p class="notice notice-warning inline" style="padding:8px 12px">
					<?php
					printf(
						/* translators: %s: client name */
						esc_html__( '%s connects from its own servers and cannot reach a site that is only available locally.', 'mcp-oauth' ),
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
					<h4 class="mcp-oauth-manual"><?php esc_html_e( 'Or set it up by hand', 'mcp-oauth' ); ?></h4>
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
					<button type="button" class="button button-small mcp-oauth-copy" data-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Copy', 'mcp-oauth' ); ?></button>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $client['command'] ) ) : ?>
				<div class="mcp-oauth-snippet">
					<pre class="is-shell"><code><?php echo esc_html( $client['command'] ); ?></code></pre>
					<button type="button" class="button button-small mcp-oauth-copy" data-copy="<?php echo esc_attr( $client['command'] ); ?>"><?php esc_html_e( 'Copy', 'mcp-oauth' ); ?></button>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $client['snippet_intro'] ) ) : ?>
				<p><?php echo esc_html( $client['snippet_intro'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $client['snippet'] ) ) : ?>
				<div class="mcp-oauth-snippet">
					<pre><code><?php echo esc_html( $client['snippet'] ); ?></code></pre>
					<button type="button" class="button button-small mcp-oauth-copy" data-copy="<?php echo esc_attr( $client['snippet'] ); ?>"><?php esc_html_e( 'Copy', 'mcp-oauth' ); ?></button>
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
				<p class="description"><?php esc_html_e( 'Uses the mcp-remote bridge (needs Node.js). It opens the browser for sign-in the first time the client starts the server.', 'mcp-oauth' ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $client['note'] ) ) : ?>
				<p class="description"><?php echo esc_html( $client['note'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $client['docs_url'] ) ) : ?>
				<p><a href="<?php echo esc_url( $client['docs_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Official setup documentation', 'mcp-oauth' ); ?></a></p>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
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
	<h2><?php esc_html_e( 'WebMCP', 'mcp-oauth' ); ?></h2>
	<p><?php esc_html_e( 'Open this site in a browser with WebMCP support. Its AI assistant can use the available abilities as the current visitor, without adding an MCP server URL.', 'mcp-oauth' ); ?></p>
	<p><?php esc_html_e( 'Choose which abilities are available on the Abilities tab. Their WordPress permission checks apply to browser assistants too.', 'mcp-oauth' ); ?></p>
	<?php if ( class_exists( '\WebMCP\Plugin', false ) ) : ?>
		<p><?php esc_html_e( 'The standalone WebMCP Abilities plugin currently provides the browser connection. Deactivate it to use the connection included here.', 'mcp-oauth' ); ?></p>
	<?php endif; ?>
	<?php if ( ! \MCP_OAuth\WebMCP\Secure_Context::is_available() ) : ?>
		<p><?php esc_html_e( 'Browser connections require HTTPS or a local loopback address.', 'mcp-oauth' ); ?></p>
	<?php endif; ?>
	<?php if ( $is_admin ) : ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'mcp_connect_webmcp' ); ?>
			<input type="hidden" name="wmcp_enabled" value="0">
			<p><label><input type="checkbox" name="wmcp_enabled" id="wmcp_enabled" value="1"<?php checked( $settings->is_enabled() ); ?>> <?php esc_html_e( 'Enable browser connections through WebMCP', 'mcp-oauth' ); ?></label></p>
			<?php submit_button( __( 'Save browser setting', 'mcp-oauth' ), 'secondary' ); ?>
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
	<h2><?php esc_html_e( 'Your connected clients', 'mcp-oauth' ); ?></h2>
	<?php if ( ! $mine ) : ?>
		<p><?php esc_html_e( 'No AI client is connected to your account yet.', 'mcp-oauth' ); ?></p>
	<?php else : ?>
		<?php connections_table( $mine, false ); ?>
	<?php endif; ?>

	<?php if ( $is_admin ) : ?>
		<?php $all = Storage\list_connections(); ?>
		<h2><?php esc_html_e( 'All connections on this site', 'mcp-oauth' ); ?></h2>
		<?php if ( ! $all ) : ?>
			<p><?php esc_html_e( 'No AI client is connected to any account.', 'mcp-oauth' ); ?></p>
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
				<th><?php esc_html_e( 'Client', 'mcp-oauth' ); ?></th>
				<th><?php esc_html_e( 'Type', 'mcp-oauth' ); ?></th>
				<?php if ( $show_user ) : ?>
					<th><?php esc_html_e( 'User', 'mcp-oauth' ); ?></th>
				<?php endif; ?>
				<?php if ( $show_server ) : ?>
					<th><?php esc_html_e( 'MCP server', 'mcp-oauth' ); ?></th>
				<?php endif; ?>
				<th><?php esc_html_e( 'Connected', 'mcp-oauth' ); ?></th>
				<th><?php esc_html_e( 'Last used', 'mcp-oauth' ); ?></th>
				<th><?php esc_html_e( 'Expires if unused', 'mcp-oauth' ); ?></th>
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
				<td><?php esc_html_e( 'OAuth 2.1', 'mcp-oauth' ); ?></td>
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
						<button type="submit" class="button button-small"><?php esc_html_e( 'Revoke', 'mcp-oauth' ); ?></button>
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
	<h2 id="abilities"><?php esc_html_e( 'Abilities', 'mcp-oauth' ); ?></h2>
	<p><?php esc_html_e( 'Choose what AI assistants can use. Each ability always checks the user’s permissions.', 'mcp-oauth' ); ?></p>
	<?php if ( ! $rows ) : ?>
		<p><?php esc_html_e( 'No abilities are registered.', 'mcp-oauth' ); ?></p>
		<?php return; ?>
	<?php endif; ?>
	<div class="mcp-oauth-ability-toolbar">
		<label class="screen-reader-text" for="mcp-oauth-ability-search"><?php esc_html_e( 'Search abilities', 'mcp-oauth' ); ?></label>
		<input type="search" id="mcp-oauth-ability-search" placeholder="<?php esc_attr_e( 'Search abilities…', 'mcp-oauth' ); ?>">
		<label class="screen-reader-text" for="mcp-oauth-ability-filter"><?php esc_html_e( 'Filter abilities', 'mcp-oauth' ); ?></label>
		<select id="mcp-oauth-ability-filter">
			<option value="all"><?php esc_html_e( 'All abilities', 'mcp-oauth' ); ?></option>
			<option value="available"><?php esc_html_e( 'Available', 'mcp-oauth' ); ?></option>
			<option value="hidden"><?php esc_html_e( 'Hidden', 'mcp-oauth' ); ?></option>
		</select>
		<span class="description"><strong id="mcp-oauth-visible-count"><?php echo (int) $visible; ?></strong> <?php esc_html_e( 'available', 'mcp-oauth' ); ?></span>
	</div>
	<div class="mcp-oauth-scroll">
	<table id="mcp-oauth-abilities-table" class="widefat striped" style="max-width:1100px">
		<thead>
			<tr>
				<th class="mcp-oauth-ability-column"><?php esc_html_e( 'Ability', 'mcp-oauth' ); ?></th>
				<th><?php esc_html_e( 'What it does', 'mcp-oauth' ); ?></th>
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
						<button type="button" class="mcp-oauth-eye" data-ability="<?php echo esc_attr( $row['name'] ); ?>" data-visible="<?php echo $row['visible'] ? '1' : '0'; ?>" aria-pressed="<?php echo $row['visible'] ? 'true' : 'false'; ?>" title="<?php echo esc_attr( $row['visible'] ? __( 'Visible — click to hide', 'mcp-oauth' ) : __( 'Hidden — click to show', 'mcp-oauth' ) ); ?>"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></button>
					<?php else : ?>
						<span title="<?php echo esc_attr( $row['visible'] ? __( 'Visible', 'mcp-oauth' ) : __( 'Hidden', 'mcp-oauth' ) ); ?>"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></span>
					<?php endif; ?>
						<strong><?php echo esc_html( $row['label'] ); ?></strong>
					</div>
						<div class="mcp-oauth-ability-description"><?php echo esc_html( $row['description'] ); ?></div>
					</div>
					<details class="mcp-oauth-ability-details">
						<summary><?php esc_html_e( 'Details', 'mcp-oauth' ); ?></summary>
						<p><code><?php echo esc_html( $row['name'] ); ?></code> · <?php echo esc_html( $row['type'] ); ?></p>
						<p class="mcp-oauth-reason<?php echo $row['override'] ? ' is-override' : ''; ?>"><?php echo esc_html( $row['reason'] ); ?></p>

					<?php if ( $row['tool_type'] ) : ?>
						<label><input type="checkbox" class="mcp-oauth-tool" data-ability="<?php echo esc_attr( $row['name'] ); ?>"<?php checked( $row['direct'] ); ?><?php disabled( ! $is_admin || ! $row['visible'] ); ?> title="<?php echo esc_attr( $row['visible'] ? __( 'Expose this ability as a tool of its own', 'mcp-oauth' ) : __( 'Hidden abilities cannot be tools', 'mcp-oauth' ) ); ?>"> <?php esc_html_e( 'Add to the MCP client’s tool list', 'mcp-oauth' ); ?></label>
						<p class="description"><?php esc_html_e( 'MCP clients can already discover and use this ability. Enable this to give it its own entry in their tool list, so they can call it directly. Each added entry takes up space in the client’s tool list. WebMCP already lists available abilities individually.', 'mcp-oauth' ); ?></p>
					<?php endif; ?>

					</details>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<p id="mcp-oauth-no-abilities" hidden role="status"><?php esc_html_e( 'No abilities match your search.', 'mcp-oauth' ); ?></p>
	<details class="mcp-oauth-ability-help">
		<summary><?php esc_html_e( 'How access works', 'mcp-oauth' ); ?></summary>
		<p><strong id="mcp-oauth-direct-count"><?php echo (int) $direct; ?></strong> <?php esc_html_e( 'direct MCP tools', 'mcp-oauth' ); ?></p>
		<p><?php esc_html_e( 'The eye controls whether an ability is available through MCP and WebMCP. Both protocols use the same abilities and WordPress permissions. In Details, you can also add an ability to the MCP client’s tool list as a direct shortcut.', 'mcp-oauth' ); ?></p>
	</details>
	<?php if ( \MCP_OAuth\adapter_available() ) : ?>
		<details class="mcp-oauth-ability-help" id="mcp-oauth-tool-inventory">
			<summary><?php esc_html_e( 'MCP client tool list', 'mcp-oauth' ); ?></summary>
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
	<h2 id="tools"><?php esc_html_e( 'Tools exposed to connected clients', 'mcp-oauth' ); ?></h2>
	<p><?php esc_html_e( 'For troubleshooting: this is the MCP tool inventory advertised to connected clients. The adapter’s helper tools discover and execute available abilities; direct tools provide shortcuts to individual abilities.', 'mcp-oauth' ); ?></p>
	<?php if ( ! $servers ) : ?>
		<p><?php esc_html_e( 'No MCP server is registered.', 'mcp-oauth' ); ?></p>
		<?php return; ?>
	<?php endif; ?>
	<?php foreach ( $servers as $server ) : ?>
		<?php $rows = Servers\inventory( $server ); ?>
		<h3><?php echo esc_html( $server->get_server_name() ); ?></h3>
		<p class="description"><code><?php echo esc_html( Servers\resource( $server ) ); ?></code></p>
		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'This server exposes nothing at all.', 'mcp-oauth' ); ?></p>
			<?php continue; ?>
		<?php endif; ?>
		<div class="mcp-oauth-scroll">
		<table class="widefat striped" style="max-width:1100px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'mcp-oauth' ); ?></th>
					<th><?php esc_html_e( 'Type', 'mcp-oauth' ); ?></th>
					<th><?php esc_html_e( 'Ability', 'mcp-oauth' ); ?></th>
					<th><?php esc_html_e( 'Description', 'mcp-oauth' ); ?></th>
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
 * Inline styles and the little bit of script the page needs (tabs, copy buttons).
 */
function render_assets(): void {
	?>
	<style>
		.mcp-oauth .mcp-oauth-endpoint { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:8px 0; }
		.mcp-oauth .mcp-oauth-endpoint code { font-size:14px; padding:6px 10px; word-break:break-all; }
		.mcp-oauth .mcp-oauth-tabs { display:flex; flex-wrap:wrap; gap:6px; margin:8px 0 12px; }
		.mcp-oauth .mcp-oauth-tab { border:1px solid #c3c4c7; background:#f6f7f7; border-radius:4px; padding:6px 12px; cursor:pointer; }
		.mcp-oauth .mcp-oauth-tab[aria-selected="true"] { background:#2271b1; border-color:#2271b1; color:#fff; }
		.mcp-oauth .mcp-oauth-client { max-width:900px; margin-top:0; }
		.mcp-oauth .mcp-oauth-client h3 { margin-top:0; }
		.mcp-oauth .mcp-oauth-oneclick { display:flex; flex-wrap:wrap; align-items:center; gap:12px; }
		.mcp-oauth .mcp-oauth-manual { margin:20px 0 6px; font-size:13px; text-transform:uppercase; letter-spacing:.02em; color:#646970; }
		.mcp-oauth .mcp-oauth-snippet { position:relative; }
		.mcp-oauth .mcp-oauth-snippet pre { background:#f6f7f7; border:1px solid #dcdcde; padding:12px 80px 12px 12px; overflow:auto; margin:0 0 8px; }
		.mcp-oauth .mcp-oauth-snippet pre.is-shell { white-space:pre-wrap; word-break:break-all; }
		.mcp-oauth .mcp-oauth-snippet pre code { background:transparent; padding:0; font-size:13px; }
		.mcp-oauth .mcp-oauth-snippet .mcp-oauth-copy { position:absolute; top:6px; right:6px; }
		.mcp-oauth .mcp-oauth-paths { margin:0 0 12px; }
		.mcp-oauth h2 { margin-top:1.5em; }
		.mcp-oauth .nav-tab-wrapper { margin-top:12px; }
		.mcp-oauth .mcp-oauth-eye { background:none; border:0; padding:0; cursor:pointer; line-height:1; }
		.mcp-oauth .mcp-oauth-eye:disabled { opacity:.5; cursor:wait; }
		.mcp-oauth .mcp-oauth-ability.is-hidden { color:#646970; }
		.mcp-oauth .mcp-oauth-scroll { overflow-x:auto; max-width:100%; }
		.mcp-oauth .mcp-oauth-ability-toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; max-width:1100px; margin:20px 0 12px; }
		.mcp-oauth #mcp-oauth-ability-search { min-width:240px; }
		.mcp-oauth .mcp-oauth-ability-toolbar .description { margin-left:auto; }
		.mcp-oauth .mcp-oauth-ability-column { width:34%; }
		.mcp-oauth .mcp-oauth-ability-summary { display:grid; grid-template-columns:34% 1fr; gap:14px; align-items:start; }
		.mcp-oauth .mcp-oauth-ability-description { line-height:1.6; }
		.mcp-oauth .mcp-oauth-ability td { padding:12px 14px; vertical-align:middle; }
		.mcp-oauth .mcp-oauth-ability-heading { display:flex; align-items:center; gap:10px; }
		.mcp-oauth .mcp-oauth-ability-details { margin:6px 0 0 30px; }
		.mcp-oauth .mcp-oauth-ability-details summary { font-size:12px; color:#646970; cursor:pointer; }
		.mcp-oauth .mcp-oauth-ability-details p { margin:10px 0; }
		.mcp-oauth .mcp-oauth-ability-details .description { max-width:70em; }
		@media (max-width:782px) { .mcp-oauth .mcp-oauth-ability-summary { grid-template-columns:1fr; gap:6px; } }
		.mcp-oauth .mcp-oauth-ability-help { max-width:1100px; margin:16px 0; }
		.mcp-oauth .mcp-oauth-ability-help summary { cursor:pointer; color:#646970; }
		.mcp-oauth .mcp-oauth-reason.is-override { color:#b26200; font-weight:600; }
	</style>
	<script>
	( function () {
		var tabs = document.querySelectorAll( '.mcp-oauth-tab' );
		var key = 'mcp-oauth-tab';
		function show( id ) {
			tabs.forEach( function ( tab ) {
				var on = tab.dataset.target === id;
				tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				var panel = document.getElementById( tab.dataset.target );
				if ( panel ) { panel.hidden = ! on; }
			} );
			try { window.localStorage.setItem( key, id ); } catch ( e ) {}
		}
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () { show( tab.dataset.target ); } );
		} );
		if ( tabs.length ) {
			var saved = null;
			try { saved = window.localStorage.getItem( key ); } catch ( e ) {}
			show( saved && document.getElementById( saved ) ? saved : tabs[0].dataset.target );
		}
		function filterAbilities() {
			var search = document.getElementById( 'mcp-oauth-ability-search' );
			var filter = document.getElementById( 'mcp-oauth-ability-filter' );
			if ( ! search || ! filter ) { return; }
			var query = search.value.trim().toLowerCase();
			var matches = 0;
			document.querySelectorAll( '.mcp-oauth-ability' ).forEach( function ( row ) {
				var hidden = row.classList.contains( 'is-hidden' );
				row.hidden = row.dataset.search.indexOf( query ) === -1 || ( filter.value === 'available' && hidden ) || ( filter.value === 'hidden' && ! hidden );
				if ( ! row.hidden ) { matches++; }
			} );
			document.getElementById( 'mcp-oauth-no-abilities' ).hidden = matches > 0;
		}
		[ 'mcp-oauth-ability-search', 'mcp-oauth-ability-filter' ].forEach( function ( id ) {
			var control = document.getElementById( id );
			if ( control ) { control.addEventListener( 'input', filterAbilities ); }
		} );
		document.querySelectorAll( '.mcp-oauth-eye' ).forEach( function ( eye ) {
			eye.addEventListener( 'click', function () {
				var hide = eye.dataset.visible === '1';
				eye.disabled = true;
				window.fetch( <?php echo wp_json_encode( rest_url( \MCP_OAuth\REST_NAMESPACE . '/abilities/visibility' ) ); ?>, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?> },
					body: JSON.stringify( { ability: eye.dataset.ability, hide: hide } )
				} ).then( function ( r ) { return r.ok ? r.json() : Promise.reject( r ); } ).then( function ( data ) {
					var row = eye.closest( 'tr' );
					eye.dataset.visible = data.visible ? '1' : '0';
					eye.setAttribute( 'aria-pressed', data.visible ? 'true' : 'false' );
					eye.title = data.visible ? <?php echo wp_json_encode( __( 'Visible — click to hide', 'mcp-oauth' ) ); ?> : <?php echo wp_json_encode( __( 'Hidden — click to show', 'mcp-oauth' ) ); ?>;
					eye.innerHTML = data.visible ? '<span class="dashicons dashicons-visibility" style="color:#00a32a"></span>' : '<span class="dashicons dashicons-hidden"></span>';
					row.classList.toggle( 'is-hidden', ! data.visible );
					filterAbilities();
					var reason = row.querySelector( '.mcp-oauth-reason' );
					reason.textContent = data.reason;
					reason.classList.toggle( 'is-override', !! data.override );
					var count = document.getElementById( 'mcp-oauth-visible-count' );
					if ( count ) { count.textContent = data.count; }
					// A hidden ability is unreachable, so it cannot be a tool of its own either.
					var tool = row.querySelector( '.mcp-oauth-tool' );
					if ( tool ) {
						tool.disabled = ! data.visible;
						tool.title = data.visible ? <?php echo wp_json_encode( __( 'Expose this ability as a tool of its own', 'mcp-oauth' ) ); ?> : <?php echo wp_json_encode( __( 'Hidden abilities cannot be tools', 'mcp-oauth' ) ); ?>;
					}
				} ).catch( function () {
					window.alert( <?php echo wp_json_encode( __( 'Could not change the visibility. Reload the page and try again.', 'mcp-oauth' ) ); ?> );
				} ).then( function () { eye.disabled = false; } );
			} );
		} );
		document.querySelectorAll( '.mcp-oauth-tool' ).forEach( function ( tool ) {
			tool.addEventListener( 'change', function () {
				var expose = tool.checked;
				tool.disabled = true;
				window.fetch( <?php echo wp_json_encode( rest_url( \MCP_OAuth\REST_NAMESPACE . '/abilities/tool' ) ); ?>, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?> },
					body: JSON.stringify( { ability: tool.dataset.ability, expose: expose } )
				} ).then( function ( r ) { return r.ok ? r.json() : Promise.reject( r ); } ).then( function ( data ) {
					tool.checked = data.expose;
					var count = document.getElementById( 'mcp-oauth-direct-count' );
					if ( count ) { count.textContent = data.direct; }
				} ).catch( function () {
					tool.checked = ! expose;
					window.alert( <?php echo wp_json_encode( __( 'Could not change the tools. Reload the page and try again.', 'mcp-oauth' ) ); ?> );
				} ).then( function () { tool.disabled = false; } );
			} );
		} );

		document.querySelectorAll( '.mcp-oauth-copy' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var label = button.textContent;
				navigator.clipboard.writeText( button.dataset.copy ).then( function () {
					button.textContent = '<?php echo esc_js( __( 'Copied', 'mcp-oauth' ) ); ?>';
					setTimeout( function () { button.textContent = label; }, 1500 );
				} );
			} );
		} );
	} )();
	</script>
	<?php
}
