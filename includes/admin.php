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
	$hook = add_management_page( __( 'MCP Connect', 'mcp-oauth' ), __( 'MCP Connect', 'mcp-oauth' ), \MCP_OAuth\authorize_capability(), PAGE_SLUG, __NAMESPACE__ . '\render' );
	if ( $hook ) {
		add_action( 'load-' . $hook, __NAMESPACE__ . '\handle_actions' );
	}
}

/**
 * Warn when the MCP Adapter is missing.
 */
function dependency_notice(): void {
	if ( \MCP_OAuth\adapter_available() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' . wp_kses_post( __( '<strong>MCP Connect</strong> needs the <a href="https://wordpress.org/plugins/mcp-adapter/">MCP Adapter</a> plugin to be installed and active.', 'mcp-oauth' ) ) . '</p></div>';
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
	$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connect'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = 'connect';
	}
	?>
	<div class="wrap mcp-oauth">
		<h1><?php esc_html_e( 'MCP Connect', 'mcp-oauth' ); ?></h1>
		<p class="description" style="max-width:52em"><?php esc_html_e( 'Connect an AI assistant to this site. The assistant signs in with your WordPress account and can then use the site’s MCP tools on your behalf — no application password to copy around.', 'mcp-oauth' ); ?></p>

		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $id => $label ) : ?>
				<a class="nav-tab<?php echo $tab === $id ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( page_url( array( 'tab' => $id ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php if ( ! empty( $_GET['revoked'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Access revoked.', 'mcp-oauth' ); ?></p></div>
		<?php endif; ?>

		<?php if ( \MCP_OAuth\is_playground() ) : ?>
			<div class="notice notice-info inline mcp-oauth-playground-notice"><p>
				<?php
				printf(
					/* translators: %s: link to the Playground Query API documentation */
					esc_html__( 'This site runs in WordPress Playground, which lives in your browser and cannot be reached by AI clients, so none of them can connect to it. To use MCP with Playground, see the %s.', 'mcp-oauth' ),
					'<a href="https://developer.wordpress.org/playground/developers/apis/query-api/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Playground Query API documentation', 'mcp-oauth' ) . '</a>'
				);
				?>
			</p></div>
		<?php endif; ?>
		<?php if ( ! \MCP_OAuth\adapter_available() ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'The MCP Adapter plugin is not active, so there is no MCP server to connect to.', 'mcp-oauth' ); ?></p></div>
		<?php elseif ( ! \MCP_OAuth\transport_allowed() ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'This site is served over plain HTTP. Sign-in tokens would travel unencrypted, so the OAuth endpoints are disabled until the site uses HTTPS.', 'mcp-oauth' ); ?></p></div>
		<?php elseif ( ! $server ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'No MCP server is registered. The MCP Adapter’s default server is missing — check the mcp_adapter_create_default_server filter.', 'mcp-oauth' ); ?></p></div>
		<?php endif; ?>

		<?php if ( 'connect' === $tab && $server && \MCP_OAuth\transport_allowed() ) : ?>
			<?php render_connect( $server, $servers, $url, $is_admin ); ?>
		<?php elseif ( 'abilities' === $tab && \MCP_OAuth\adapter_available() ) : ?>
			<?php render_abilities( $is_admin ); ?>
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
		<?php esc_html_e( 'For your convenience, here are some common AI agents that can use your WordPress through MCP. Each signs in to this site through your browser; no password is copied anywhere.', 'mcp-oauth' ); ?>
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
				<p><a class="button button-primary button-hero" href="<?php echo esc_url( $client['link']['url'], array( 'https', 'cursor' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $client['link']['label'] ); ?></a></p>
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
		</div>
	<?php endforeach; ?>
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
 * Which abilities the MCP Adapter exposes to connected clients, and the switch
 * to expose them all.
 */
function render_abilities( bool $is_admin ): void {
	$rows    = Abilities\report();
	$visible = count( array_filter( array_column( $rows, 'visible' ) ) );
	?>
	<h2 id="abilities"><?php esc_html_e( 'Abilities visible to connected clients', 'mcp-oauth' ); ?></h2>
	<p>
		<?php
		printf(
			/* translators: 1: number of visible abilities, 2: number of registered abilities */
			esc_html__( 'Connected clients can discover and run %1$s of the %2$s abilities registered on this site. Every request arrives as the signed-in user, and each ability checks its own permissions for that user — that, not a “public” flag, is what limits what a client can do.', 'mcp-oauth' ),
			'<strong id="mcp-oauth-visible-count">' . (int) $visible . '</strong>',
			'<strong>' . count( $rows ) . '</strong>'
		);
		if ( $is_admin ) {
			echo ' ' . esc_html__( 'Click the eye to hide an ability from clients, or show it again.', 'mcp-oauth' );
		}
		?>
	</p>
	<?php if ( ! $rows ) : ?>
		<p><?php esc_html_e( 'No abilities are registered.', 'mcp-oauth' ); ?></p>
		<?php return; ?>
	<?php endif; ?>
	<table class="widefat striped" style="max-width:1100px">
		<thead>
			<tr>
				<th style="width:1.5em"></th>
				<th><?php esc_html_e( 'Ability', 'mcp-oauth' ); ?></th>
				<th><?php esc_html_e( 'Label', 'mcp-oauth' ); ?></th>
				<th><?php esc_html_e( 'Type', 'mcp-oauth' ); ?></th>
				<th><?php esc_html_e( 'Why', 'mcp-oauth' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $rows as $row ) : ?>
			<tr class="mcp-oauth-ability<?php echo $row['visible'] ? '' : ' is-hidden'; ?>" data-ability="<?php echo esc_attr( $row['name'] ); ?>">
				<td>
					<?php $icon = $row['visible'] ? '<span class="dashicons dashicons-visibility" style="color:#00a32a"></span>' : '<span class="dashicons dashicons-hidden"></span>'; ?>
					<?php if ( $is_admin && ! $row['locked'] ) : ?>
						<button type="button" class="mcp-oauth-eye" data-ability="<?php echo esc_attr( $row['name'] ); ?>" data-visible="<?php echo $row['visible'] ? '1' : '0'; ?>" aria-pressed="<?php echo $row['visible'] ? 'true' : 'false'; ?>" title="<?php echo esc_attr( $row['visible'] ? __( 'Visible — click to hide', 'mcp-oauth' ) : __( 'Hidden — click to show', 'mcp-oauth' ) ); ?>"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></button>
					<?php else : ?>
						<span title="<?php echo esc_attr( $row['visible'] ? __( 'Visible', 'mcp-oauth' ) : __( 'Hidden', 'mcp-oauth' ) ); ?>"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></span>
					<?php endif; ?>
				</td>
				<td><code><?php echo esc_html( $row['name'] ); ?></code></td>
				<td><?php echo esc_html( $row['label'] ); ?></td>
				<td><?php echo esc_html( $row['type'] ); ?></td>
				<td class="mcp-oauth-reason<?php echo $row['override'] ? ' is-override' : ''; ?>"><?php echo esc_html( $row['reason'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
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
					var reason = row.querySelector( '.mcp-oauth-reason' );
					reason.textContent = data.reason;
					reason.classList.toggle( 'is-override', !! data.override );
					var count = document.getElementById( 'mcp-oauth-visible-count' );
					if ( count ) { count.textContent = data.count; }
				} ).catch( function () {
					window.alert( <?php echo wp_json_encode( __( 'Could not change the visibility. Reload the page and try again.', 'mcp-oauth' ) ); ?> );
				} ).then( function () { eye.disabled = false; } );
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
