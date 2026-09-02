<?php
/**
 * PHPUnit bootstrap: minimal WordPress stubs so the plugin's logic can be
 * tested without a WordPress install. Anything that needs real WordPress is
 * covered by the Playground-backed e2e suite in tests/e2e.
 *
 * @package mcp-connect
 */

// phpcs:ignoreFile

namespace {
	define( 'ABSPATH', __DIR__ . '/stubs/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );

	if ( file_exists( __DIR__ . '/../vendor/autoload.php' ) ) {
		require_once __DIR__ . '/../vendor/autoload.php';
	}

	$GLOBALS['wp_test'] = array();

	/**
	 * Reset every piece of fake WordPress state between tests.
	 */
	function wp_test_reset(): void {
		$GLOBALS['wp_test'] = array(
			'options'     => array(),
			'transients'  => array(),
			'filters'     => array(),
			'actions'     => array(),
			'home_url'    => 'https://example.com',
			'environment' => 'production',
			'logged_in'   => false,
			'user_id'     => 0,
			'caps'        => array(),
			'blogname'    => 'Example Site',
			'servers'     => array(),
			'abilities'   => array(),
			'permalinks'  => true,
		);
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_SERVER['REQUEST_URI']    = '/';
		$_SERVER['QUERY_STRING']   = '';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		unset( $GLOBALS['pagenow'] );
	}
	wp_test_reset();

	/* ----------------------------------------------------------------- i18n / escaping */
	function __( $text, $domain = 'default' ) { return $text; }
	function _e( $text, $domain = 'default' ) { echo $text; }
	function esc_html__( $text, $domain = 'default' ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_html_e( $text, $domain = 'default' ) { echo esc_html__( $text ); }
	function esc_attr__( $text, $domain = 'default' ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_js( $text ) { return addslashes( (string) $text ); }
	function esc_url( $url, $protocols = null ) { return $url; }
	function esc_url_raw( $url ) { return $url; }
	function wp_kses( $text, $allowed ) { return $text; }
	function wp_kses_post( $text ) { return $text; }
	function sanitize_text_field( $text ) {
		$text = strip_tags( (string) $text );
		$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
		$text = preg_replace( '/%[a-f0-9]{2}/i', '', $text );
		return trim( $text );
	}
	function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
	function sanitize_title( $title ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $title ) ), '-' ); }
	function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value ); }
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }
	function trailingslashit( $value ) { return untrailingslashit( $value ) . '/'; }
	function absint( $value ) { return abs( (int) $value ); }

	/* ----------------------------------------------------------------- URLs */
	function home_url( $path = '' ) { return $GLOBALS['wp_test']['home_url'] . ( $path ? '/' . ltrim( $path, '/' ) : '' ); }
	function site_url( $path = '' ) { return home_url( $path ); }
	function admin_url( $path = '' ) { return home_url( 'wp-admin/' . ltrim( $path, '/' ) ); }
	function rest_url( $path = '' ) {
		if ( $GLOBALS['wp_test']['permalinks'] ) {
			return home_url( 'wp-json/' . ltrim( $path, '/' ) );
		}
		return home_url( '?rest_route=/' . ltrim( $path, '/' ) );
	}
	function wp_login_url( $redirect = '' ) {
		$url = home_url( 'wp-login.php' );
		return $redirect ? $url . '?redirect_to=' . rawurlencode( $redirect ) : $url;
	}
	function wp_logout_url( $redirect = '' ) { return home_url( 'wp-login.php?action=logout&redirect_to=' . rawurlencode( $redirect ) ); }
	function add_query_arg( ...$args ) {
		if ( is_array( $args[0] ) ) {
			$params = $args[0];
			$url    = $args[1] ?? '';
		} else {
			$params = array( $args[0] => $args[1] );
			$url    = $args[2] ?? '';
		}
		$parts = parse_url( $url );
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}
		$query = array_merge( $query, $params );
		$base  = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' ) . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '' );
		return $base . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}
	function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
	function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }

	/* ----------------------------------------------------------------- hooks */
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) { add_filter( $hook, $callback, $priority, $args ); }
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['wp_test']['filters'][ $hook ][ $priority ][] = $callback; }
	function has_filter( $hook, $callback = false ) { return ! empty( $GLOBALS['wp_test']['filters'][ $hook ] ); }
	function remove_action( $hook, $callback, $priority = 10 ) { return true; }
	function apply_filters( $hook, $value, ...$args ) {
		$filters = $GLOBALS['wp_test']['filters'][ $hook ] ?? array();
		ksort( $filters );
		foreach ( $filters as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = $callback( $value, ...$args );
			}
		}
		return $value;
	}
	function do_action( $hook, ...$args ) { $GLOBALS['wp_test']['actions'][] = $hook; }
	function did_action( $hook ) { return count( array_keys( $GLOBALS['wp_test']['actions'], $hook, true ) ); }
	function register_activation_hook( $file, $callback ) {}
	function register_deactivation_hook( $file, $callback ) {}
	function register_rest_route( $ns, $route, $args ) { $GLOBALS['wp_test']['routes'][ $ns . $route ] = $args; return true; }

	/* ----------------------------------------------------------------- options / transients */
	function get_option( $name, $default = false ) { return $GLOBALS['wp_test']['options'][ $name ] ?? $default; }
	function update_option( $name, $value, $autoload = null ) { $GLOBALS['wp_test']['options'][ $name ] = $value; return true; }
	function add_option( $name, $value, $d = '', $autoload = null ) { if ( ! isset( $GLOBALS['wp_test']['options'][ $name ] ) ) { $GLOBALS['wp_test']['options'][ $name ] = $value; } return true; }
	function delete_option( $name ) { unset( $GLOBALS['wp_test']['options'][ $name ] ); return true; }
	function get_transient( $name ) { return $GLOBALS['wp_test']['transients'][ $name ] ?? false; }
	function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['wp_test']['transients'][ $name ] = $value; return true; }
	function delete_transient( $name ) { unset( $GLOBALS['wp_test']['transients'][ $name ] ); return true; }
	function wp_next_scheduled( $hook ) { return false; }
	function wp_schedule_event( $ts, $recurrence, $hook ) { return true; }
	function wp_clear_scheduled_hook( $hook ) {}

	/* ----------------------------------------------------------------- site / users */
	function wp_get_environment_type() { return $GLOBALS['wp_test']['environment']; }
	function get_bloginfo( $show = '' ) { return 'name' === $show ? $GLOBALS['wp_test']['blogname'] : ''; }
	function is_user_logged_in() { return $GLOBALS['wp_test']['logged_in']; }
	function get_current_user_id() { return $GLOBALS['wp_test']['user_id']; }
	function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['wp_test']['caps'], true ); }
	function user_can( $user, $cap ) { return in_array( $cap, $GLOBALS['wp_test']['caps'], true ); }
	function get_user_by( $field, $value ) { return $value > 0 ? (object) array( 'ID' => $value, 'user_login' => 'user' . $value, 'display_name' => 'User ' . $value ) : false; }
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
	function is_admin() { return false; }
	function wp_create_nonce( $action ) { return 'nonce'; }
	function nocache_headers() {}
	function rest_convert_error_to_response( $error ) {
		$data = $error->get_error_data();
		return new WP_REST_Response( array( 'code' => $error->get_error_code(), 'message' => $error->get_error_message() ), is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500 );
	}

	/* ----------------------------------------------------------------- classes */
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
	class WP_REST_Request {
		private $params; private $headers; private $route; private $method;
		public function __construct( $method = 'POST', $route = '/', $params = array(), $headers = array() ) {
			$this->method = $method; $this->route = $route; $this->params = $params;
			$this->headers = array_change_key_case( $headers, CASE_LOWER );
		}
		public function get_param( $key ) { return $this->params[ $key ] ?? null; }
		public function get_json_params() { return $this->params; }
		public function get_header( $key ) { return $this->headers[ strtolower( $key ) ] ?? null; }
		public function get_route() { return $this->route; }
		public function get_method() { return $this->method; }
	}
	class WP_REST_Response {
		public $data; public $status; public $headers = array();
		public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
		public function get_data() { return $this->data; }
		public function get_status() { return $this->status; }
		public function header( $key, $value ) { $this->headers[ $key ] = $value; }
	}

	class WP_Ability {
		private $name; private $args;
		public function __construct( $name, $args ) { $this->name = $name; $this->args = $args; }
		public function get_name() { return $this->name; }
		public function get_label() { return $this->args['label'] ?? $this->name; }
		public function get_meta() { return $this->args['meta'] ?? array(); }
	}

	/**
	 * Register a fake ability, running it through the registration filter the
	 * way the Abilities API does.
	 */
	function wp_test_register_ability( $name, $args = array() ) {
		$args = apply_filters( 'wp_register_ability_args', $args, $name );
		$GLOBALS['wp_test']['abilities'][ $name ] = new WP_Ability( $name, $args );
	}
	function wp_get_abilities() { return $GLOBALS['wp_test']['abilities']; }
	function wp_get_ability( $name ) { return $GLOBALS['wp_test']['abilities'][ $name ] ?? null; }

	require_once __DIR__ . '/stubs/class-mcp-adapter.php';
	require_once __DIR__ . '/../mcp-connect.php';
}
