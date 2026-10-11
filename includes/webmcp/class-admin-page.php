<?php
/**
 * Admin settings page renderer.
 *
 * Adapted from WebMCP Abilities 0.8.0 by Code Atlantic (GPL-2.0-or-later).
 *
 * @package mcp-connect
 */

namespace MCP_OAuth\WebMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Settings → WebMCP admin page and registers its fields.
 */
class Admin_Page {

	/**
	 * Plugin settings instance.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Ability bridge instance.
	 *
	 * @var Ability_Bridge
	 */
	private Ability_Bridge $bridge;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Plugin settings.
	 * @param Ability_Bridge $bridge   Ability bridge.
	 */
	public function __construct( Settings $settings, Ability_Bridge $bridge ) {
		$this->settings = $settings;
		$this->bridge   = $bridge;
	}

	/**
	 * Render WebMCP within Tools → MCP Connect.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wmcp">
			<h2><?php esc_html_e( 'WebMCP', 'mcp-oauth' ); ?></h2>
			<p><?php esc_html_e( 'AI agents in a supported browser can discover and use WordPress abilities on the front end and in wp-admin, acting as the current visitor.', 'mcp-oauth' ); ?></p>
			<?php
			if ( class_exists( '\WebMCP\Plugin', false ) ) {
				echo '<p class="notice notice-info inline">' . esc_html__( 'WebMCP Abilities is active and provides the browser bridge. Deactivate it to use the bridge included with MCP Connect; your settings are retained.', 'mcp-oauth' ) . '</p>';
			}
			$this->render_settings_form( $this->settings->is_enabled(), $this->settings->is_discovery_public() );
			$this->render_tools_table( $this->bridge->report(), $this->settings->is_discovery_public() );
			$this->render_status();
			$this->render_assets( true );
			?>
		</div>
		<?php
	}

	/**
	 * URL of the integrated WebMCP tab.
	 *
	 */
	private function page_url(): string {
		return \MCP_OAuth\Admin\page_url( array( 'tab' => 'webmcp' ) );
	}

	/**
	 * The two site-wide switches.
	 *
	 * @param bool $is_enabled Whether the bridge is on.
	 * @param bool $is_public  Whether logged-out visitors may discover tools.
	 */
	private function render_settings_form( bool $is_enabled, bool $is_public ): void {
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'mcp_connect_webmcp' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="wmcp_enabled">
							<?php esc_html_e( 'Enable WebMCP Abilities', 'mcp-oauth' ); ?>
						</label>
					</th>
					<td>
						<label>
							<input type="checkbox"
								name="<?php echo esc_attr( Settings::OPTION_ENABLED ); ?>"
								id="wmcp_enabled"
								value="1"
								<?php checked( $is_enabled ); ?>>
							<?php esc_html_e( 'Allow AI agents to use WordPress features as tools', 'mcp-oauth' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When disabled, no WebMCP tools will be registered in the browser.', 'mcp-oauth' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<?php esc_html_e( 'Tool Discovery', 'mcp-oauth' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox"
								name="<?php echo esc_attr( Settings::OPTION_DISCOVERY_PUBLIC ); ?>"
								id="wmcp_discovery_public"
								value="1"
								<?php checked( $is_public ); ?>>
							<?php esc_html_e( 'Allow agents to discover tools without logging in', 'mcp-oauth' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'The master switch for logged-out visitors. When unchecked, no tool is advertised to them whatever the Tools tab says. When checked, the tools ticked there are — names and descriptions only; execution still requires the appropriate permissions.', 'mcp-oauth' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Every registered ability, with an eye to hide it and a box to open it up
	 * to logged-out visitors.
	 *
	 * Applied as they are clicked rather than on a Save button: these are one
	 * decision each, and there can be a hundred of them.
	 *
	 * @param array<int, array<string, mixed>> $rows      Report rows.
	 * @param bool                             $is_public Whether public discovery is on.
	 */
	private function render_tools_table( array $rows, bool $is_public ): void {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No abilities are registered on this site yet.', 'mcp-oauth' ) . '</p>';
			return;
		}

		$shown     = count( array_filter( array_column( $rows, 'visible' ) ) );
		$anonymous = count( array_filter( array_column( $rows, 'anonymous' ) ) );
		?>
		<h2><?php esc_html_e( 'Configured tools', 'mcp-oauth' ); ?></h2>

		<p class="description">
			<?php
			printf(
				/* translators: 1: number configured for discovery, 2: number of registered abilities */
				esc_html__( '%1$s of %2$s registered abilities are configured for discovery. Browser discovery also requires the bridge to be enabled and HTTPS. Each tool checks the permissions of the visitor requesting it.', 'mcp-oauth' ),
				'<strong id="wmcp-count-shown">' . esc_html( (string) $shown ) . '</strong>',
				'<strong>' . esc_html( (string) count( $rows ) ) . '</strong>'
			);
			?>
			<br>
			<?php esc_html_e( 'Click the eye to hide a tool from agents, or to show it again. Tick the box to advertise it to logged-out visitors as well.', 'mcp-oauth' ); ?>
			<?php
			printf(
				/* translators: %s: number of tools configured for public discovery */
				esc_html__( 'Tools configured for public discovery: %s.', 'mcp-oauth' ),
				'<strong id="wmcp-count-anonymous">' . esc_html( (string) $anonymous ) . '</strong>'
			);
			?>
		</p>

		<?php if ( ! $is_public ) : ?>
			<p class="description wmcp-warn">
				<?php
				printf(
					/* translators: %s: link to the Settings tab */
					esc_html__( 'Public discovery is off. These public visibility choices are saved but do not advertise tools to logged-out visitors. Enable public discovery in the %s above.', 'mcp-oauth' ),
					'<a href="' . esc_url( $this->page_url() ) . '">' . esc_html__( 'Settings', 'mcp-oauth' ) . '</a>'
				);
				?>
			</p>
		<?php endif; ?>

		<p class="description"><?php esc_html_e( 'Changes to tools are saved immediately.', 'mcp-oauth' ); ?></p>
		<div id="wmcp-save-status" role="status" aria-live="polite" aria-atomic="true"></div>
		<div class="wmcp-scroll">
		<table class="widefat striped" style="max-width:1100px;">
			<thead>
				<tr>
					<th style="width:1.5em;"></th>
					<th><?php esc_html_e( 'Tool', 'mcp-oauth' ); ?></th>

					<th style="width:11em;" class="wmcp-anon-cell"><?php esc_html_e( 'Logged-out visitors', 'mcp-oauth' ); ?></th>
					<th style="width:9em;"><?php esc_html_e( 'Why', 'mcp-oauth' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
				<tr class="wmcp-ability<?php echo $row['visible'] ? '' : ' is-hidden'; ?>" data-ability="<?php echo esc_attr( $row['name'] ); ?>">
					<td>
						<button type="button"
							class="wmcp-eye"
							data-ability="<?php echo esc_attr( $row['name'] ); ?>"
							data-visible="<?php echo $row['visible'] ? '1' : '0'; ?>"
							aria-label="
							<?php
								/* translators: %s: tool name. */
								echo esc_attr( sprintf( __( 'Advertise tool: %s', 'mcp-oauth' ), $row['label'] ) );
							?>
							"
							aria-pressed="<?php echo $row['visible'] ? 'true' : 'false'; ?>"
							<?php disabled( $row['locked'] ); ?>
							title="<?php echo esc_attr( $this->eye_title( (bool) $row['visible'], (bool) $row['locked'] ) ); ?>">
							<span aria-hidden="true" class="dashicons <?php echo $row['visible'] ? 'dashicons-visibility' : 'dashicons-hidden'; ?>"<?php echo $row['visible'] ? ' style="color:#00a32a"' : ''; ?>></span>
						</button>
					</td>
					<td>
						<strong><?php echo esc_html( $row['label'] ); ?></strong>
						<br><code><?php echo esc_html( $row['name'] ); ?></code>
						<p class="description"><?php echo esc_html( $row['description'] ); ?></p>
					</td>
					<td class="wmcp-anon-cell">
						<input type="checkbox"
							class="wmcp-anon"
							aria-label="
							<?php
								/* translators: %s: tool name. */
								echo esc_attr( sprintf( __( 'Advertise to logged-out visitors: %s', 'mcp-oauth' ), $row['label'] ) );
							?>
							"
							data-ability="<?php echo esc_attr( $row['name'] ); ?>"
							<?php checked( $row['anonymous'] ); ?>
							<?php disabled( $row['locked'] || ! $row['visible'] ); ?>
							title="<?php echo esc_attr( $this->anon_title( (bool) $row['visible'], (bool) $row['locked'] ) ); ?>">
					</td>
					<td><details>
							<summary class="wmcp-reason-summary"><?php echo esc_html( $row['locked'] ? __( 'Locked by plugin', 'mcp-oauth' ) : ( $row['override'] ? __( 'Custom', 'mcp-oauth' ) : __( 'Plugin default', 'mcp-oauth' ) ) ); ?></summary>
							<p class="wmcp-reason<?php echo $row['override'] ? ' is-override' : ''; ?>"><?php echo esc_html( $row['reason'] ); ?></p>
						</details></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Tooltip for the eye button.
	 *
	 * @param bool $visible Whether the tool is advertised.
	 * @param bool $locked  Whether its own plugin opted it out.
	 */
	private function eye_title( bool $visible, bool $locked ): string {
		if ( $locked ) {
			return __( 'Its own plugin asked to keep this hidden', 'mcp-oauth' );
		}

		return $visible
			? __( 'Advertised — click to hide', 'mcp-oauth' )
			: __( 'Hidden — click to advertise', 'mcp-oauth' );
	}

	/**
	 * Tooltip for the logged-out visitors checkbox.
	 *
	 * @param bool $visible Whether the tool is advertised.
	 * @param bool $locked  Whether its own plugin opted it out.
	 */
	private function anon_title( bool $visible, bool $locked ): string {
		if ( $locked || ! $visible ) {
			return __( 'A hidden tool reaches nobody', 'mcp-oauth' );
		}

		return __( 'Advertise this tool to logged-out visitors too', 'mcp-oauth' );
	}

	/**
	 * The status readout.
	 */
	private function render_status(): void {
		$count = function_exists( 'wp_get_abilities' ) ? count( wp_get_abilities() ) : 0;
		?>
		<h2><?php esc_html_e( 'Status', 'mcp-oauth' ); ?></h2>
		<ul>
			<li>
				<?php esc_html_e( 'Secure context:', 'mcp-oauth' ); ?>
				<?php if ( Secure_Context::is_available() ) : ?>
					<span style="color:#00a32a;">✓ <?php esc_html_e( 'Available', 'mcp-oauth' ); ?></span>
				<?php else : ?>
					<span style="color:#d63638;">✗ <?php esc_html_e( 'Unavailable — use HTTPS or a local loopback address', 'mcp-oauth' ); ?></span>
				<?php endif; ?>
			</li>
			<li>
				<?php esc_html_e( 'WordPress Abilities API:', 'mcp-oauth' ); ?>
				<?php if ( function_exists( 'wp_get_abilities' ) ) : ?>
					<span style="color:#00a32a;">✓ <?php esc_html_e( 'Available', 'mcp-oauth' ); ?></span>
				<?php else : ?>
					<span style="color:#d63638;">✗ <?php esc_html_e( 'Not available', 'mcp-oauth' ); ?></span>
				<?php endif; ?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %d: number of registered abilities */
					esc_html__( 'Registered abilities: %d', 'mcp-oauth' ),
					esc_html( $count )
				);
				?>
			</li>
			<li>
				<?php esc_html_e( 'Browser support: A browser with the WebMCP API is required.', 'mcp-oauth' ); ?>
			</li>
		</ul>

		<p>
			<a href="https://github.com/code-atlantic/webmcp-abilities" target="_blank">
				<?php esc_html_e( 'Original WebMCP bridge source code →', 'mcp-oauth' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * The styles and the script behind the eye and the checkbox.
	 *
	 * Only the Tools tab has anything to drive, so the other tabs get nothing.
	 *
	 * @param bool $needed Whether the tools table is on screen.
	 */
	private function render_assets( bool $needed ): void {
		if ( ! $needed ) {
			return;
		}

		$endpoints = array(
			'visibility' => rest_url( REST_API::NAMESPACE . '/abilities/visibility' ),
			'anonymous'  => rest_url( REST_API::NAMESPACE . '/abilities/anonymous' ),
		);

		$i18n = array(
			'eyeShown'  => __( 'Advertised — click to hide', 'mcp-oauth' ),
			'eyeHidden' => __( 'Hidden — click to advertise', 'mcp-oauth' ),
			'anonOn'    => __( 'Advertise this tool to logged-out visitors too', 'mcp-oauth' ),
			'anonOff'   => __( 'A hidden tool reaches nobody', 'mcp-oauth' ),
			'saving'    => __( 'Saving…', 'mcp-oauth' ),
			'saved'     => __( 'Saved.', 'mcp-oauth' ),
			'failed'    => __( 'Could not save that change. Please try again. If the problem continues, reload the page.', 'mcp-oauth' ),
			'custom'    => __( 'Custom', 'mcp-oauth' ),
			'default'   => __( 'Plugin default', 'mcp-oauth' ),
		);
		?>
		<style>
			.wmcp .nav-tab-wrapper { margin-bottom:16px; }
			.wmcp .wmcp-ability code { overflow-wrap:anywhere; }
			.wmcp .wmcp-ability details summary { cursor:pointer; }
			.wmcp .wmcp-scroll { overflow-x:auto; max-width:100%; }
			.wmcp .wmcp-eye { background:none; border:0; padding:6px; min-width:32px; min-height:32px; cursor:pointer; line-height:1; }
			.wmcp .wmcp-eye:focus-visible { outline:2px solid var(--wp-admin-theme-color, #2271b1); outline-offset:2px; }
			.wmcp .wmcp-eye:disabled { opacity:.5; cursor:default; }
			.wmcp .wmcp-ability.is-hidden { color:#646970; }
			.wmcp .wmcp-anon-cell { text-align:center; }
			.wmcp .wmcp-anon-cell input { margin:0; }
			.wmcp .wmcp-reason.is-override { color:#b26200; font-weight:600; }
			.wmcp .wmcp-warn { color:#b26200; }
		</style>
		<script>
		( function () {
			var endpoints = <?php echo wp_json_encode( $endpoints ); ?>;
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			var i18n = <?php echo wp_json_encode( $i18n ); ?>;

			function post( url, body ) {
				return window.fetch( url, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify( body )
				} ).then( function ( r ) { return r.ok ? r.json() : Promise.reject( r ); } );
			}

			function paint( row, data ) {
				var eye = row.querySelector( '.wmcp-eye' );
				eye.dataset.visible = data.visible ? '1' : '0';
				eye.setAttribute( 'aria-pressed', data.visible ? 'true' : 'false' );
				eye.title = data.visible ? i18n.eyeShown : i18n.eyeHidden;
				eye.firstElementChild.className = 'dashicons ' + ( data.visible ? 'dashicons-visibility' : 'dashicons-hidden' );
				eye.firstElementChild.style.color = data.visible ? '#00a32a' : '';
				row.classList.toggle( 'is-hidden', ! data.visible );

				// A hidden tool reaches nobody, so it cannot reach logged-out visitors either.
				var anon = row.querySelector( '.wmcp-anon' );
				anon.checked = !! data.anonymous;
				anon.disabled = ! data.visible;
				anon.title = data.visible ? i18n.anonOn : i18n.anonOff;

				var reason = row.querySelector( '.wmcp-reason' );
				reason.textContent = data.reason;
				row.querySelector( '.wmcp-reason-summary' ).textContent = data.override ? i18n.custom : i18n.default;
				reason.classList.toggle( 'is-override', !! data.override );

				[ [ 'wmcp-count-shown', data.count_shown ], [ 'wmcp-count-anonymous', data.count_anonymous ] ].forEach( function ( pair ) {
					var el = document.getElementById( pair[0] );
					if ( el ) { el.textContent = pair[1]; }
				} );
			}

			var saving = false;
			function saveChange( row, url, body, rollback ) {
				if ( saving ) { return; }
				saving = true;
				var status = document.getElementById( 'wmcp-save-status' );
				status.className = '';
				status.textContent = i18n.saving;
				row.setAttribute( 'aria-busy', 'true' );
				// Serialize all changes: each response includes table-wide counts.
				var controls = Array.from( document.querySelectorAll( '.wmcp-eye, .wmcp-anon' ) );
				var disabled = controls.map( function ( control ) { return control.disabled; } );
				controls.forEach( function ( control ) { control.disabled = true; } );
				var result;
				post( url, body )
					.then( function ( data ) { result = data; status.textContent = i18n.saved; } )
					.catch( function () {
						if ( rollback ) { rollback(); }
						status.className = 'notice notice-error inline';
						status.textContent = i18n.failed;
					} )
					.then( function () {
						controls.forEach( function ( control, index ) { control.disabled = disabled[index]; } );
						if ( result ) { paint( row, result ); }
						row.removeAttribute( 'aria-busy' );
						saving = false;
					} );
			}

			document.querySelectorAll( '.wmcp-eye' ).forEach( function ( eye ) {
				eye.addEventListener( 'click', function () {
					var hide = eye.dataset.visible === '1';
					var row = eye.closest( 'tr' );
					saveChange( row, endpoints.visibility, { ability: eye.dataset.ability, hide: hide } );
				} );
			} );

			document.querySelectorAll( '.wmcp-anon' ).forEach( function ( anon ) {
				anon.addEventListener( 'change', function () {
					var wanted = anon.checked;
					var row = anon.closest( 'tr' );
					saveChange( row, endpoints.anonymous, { ability: anon.dataset.ability, anonymous: wanted }, function () {
						anon.checked = ! wanted;
					} );
				} );
			} );
		} )();
		</script>
		<?php
	}
}
