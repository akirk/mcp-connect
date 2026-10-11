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
			$this->render_settings_form( $this->settings->is_enabled() );
			printf(
				/* translators: %s: link to the Abilities tab. */
				'<p>' . esc_html__( 'Manage browser tool visibility on the %s tab.', 'mcp-oauth' ) . '</p>',
				'<a href="' . esc_url( \MCP_OAuth\Admin\page_url( array( 'tab' => 'abilities' ) ) ) . '">' . esc_html__( 'Abilities', 'mcp-oauth' ) . '</a>'
			);
			$this->render_status();
			?>
		</div>
		<?php
	}

	/**
	 * The two site-wide switches.
	 *
	 * @param bool $is_enabled Whether the bridge is on.
	 * @param bool $is_public  Whether logged-out visitors may discover tools.
	 */
	private function render_settings_form( bool $is_enabled ): void {
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'mcp_connect_webmcp' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="wmcp_enabled">
							<?php esc_html_e( 'Enable WebMCP', 'mcp-oauth' ); ?>
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

			</table>

			<?php submit_button(); ?>
		</form>
		<?php
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
}
