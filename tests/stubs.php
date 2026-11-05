<?php
/**
 * In-memory WordPress function doubles.
 *
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Tests {

	/**
	 * Shared state for the doubles; reset before every test.
	 */
	class Env {
		/** @var array<string,mixed> */
		public static array $options = array();
		/** @var array<string,mixed> */
		public static array $transients = array();
		/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
		public static array $requests = array();
		/** @var array<int,mixed> */
		public static array $responses = array();
		/** @var array<int,array{code:string,message:string}> */
		public static array $notices   = array();
		/** @var array<int,array<string,mixed>> */
		public static array $logRows = array();
		/** @var array<int,array{0:int,1:string,2:array<mixed>}> */
		public static array $cron = array();
		public static bool $userCan = true;
		public static bool $can        = true;
		public static bool $nonceValid = true;
		public static string $output   = '';

		public static function reset(): void {
			self::$options    = array();
			self::$transients = array();
			self::$requests   = array();
			self::$responses  = array();
			self::$notices    = array();
			self::$logRows    = array();
			self::$cron       = array();
			self::$userCan    = true;
			self::$can        = true;
			self::$nonceValid = true;
		}

		/**
		 * Queue a Woodpecker HTTP response.
		 *
		 * @param int          $code HTTP status.
		 * @param mixed|string $body Array (JSON-encoded) or raw string.
		 */
		public static function queue( int $code, $body = array() ): void {
			self::$responses[] = array(
				'response' => array( 'code' => $code ),
				'body'     => is_string( $body ) ? $body : json_encode( $body ),
			);
		}
	}

	class JsonResponse extends \Exception {
		public bool $success;
		/** @var mixed */
		public $data;
		public int $status;
	}
}

namespace {

	use Shift8\Woodpecker\Tests\Env;
	use Shift8\Woodpecker\Tests\JsonResponse;

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/tmp/' );
	}
	define( 'SHIFT8_WOODPECKER_PLUGIN_URL', 'https://wp.test/wp-content/plugins/shift8-woodpecker/' );
	define( 'SHIFT8_WOODPECKER_PLUGIN_VERSION', 'test' );

	class WP_Error {
		/** @var array<string,string[]> */
		private array $errors = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->errors[ $code ] = array( $message );
		}

		public function get_error_code() {
			return (string) array_key_first( $this->errors );
		}

		public function get_error_message() {
			return (string) reset( $this->errors )[0];
		}
	}

	class WP_User {
		public int $ID              = 7;
		public string $user_login   = 'casey';
		public string $display_name = 'Casey Admin';

		public function exists(): bool {
			return true;
		}
	}

	define( 'ARRAY_A', 'ARRAY_A' );

	class wpdb {
		public string $prefix = 'wp_';

		public function get_charset_collate() {
			return '';
		}
		public function insert( $table, $data ) {
			$data['id']     = count( Env::$logRows ) + 1;
			Env::$logRows[] = $data;
			return 1;
		}
		public function query() {
			return 1;
		}
		public function prepare( $query, $limit ) {
			return str_replace( '%d', (string) (int) $limit, $query );
		}
		public function get_results( $query ) {
			preg_match( '/LIMIT (\d+)/', $query, $match );
			return array_slice( array_reverse( Env::$logRows ), 0, (int) $match[1] );
		}
	}

	function get_userdata( $id ) {
		if ( $id < 1 ) {
			return false;
		}
		$user             = new WP_User();
		$user->ID         = $id;
		$user->user_login = 7 === $id ? 'casey' : 'user' . $id;
		return $user;
	}
	function user_can() {
		return Env::$userCan;
	}
	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
		Env::$cron[] = array( $timestamp, $hook, $args );
		return true;
	}
	function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
		Env::$cron = array_values(
			array_filter(
				Env::$cron,
				static function ( $event ) use ( $timestamp, $hook, $args ) {
					return $event !== array( $timestamp, $hook, $args );
				}
			)
		);
		return true;
	}
	function wp_clear_scheduled_hook() {
		Env::$cron = array();
	}

	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
	function __( $text ) {
		return $text;
	}
	function esc_html__( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
	function esc_html_e( $text ) {
		echo htmlspecialchars( $text, ENT_QUOTES );
	}
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES );
	}
	function wp_unslash( $value ) {
		return $value;
	}
	function sanitize_text_field( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
	function wp_parse_url( $url ) {
		return parse_url( $url );
	}
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
	function wp_remote_retrieve_response_code( $response ) {
		return $response['response']['code'];
	}
	function wp_remote_retrieve_body( $response ) {
		return $response['body'];
	}
	function wp_remote_get( $url, $args = array() ) {
		return shift8_woodpecker_test_http( 'GET', $url, $args );
	}
	function wp_remote_post( $url, $args = array() ) {
		return shift8_woodpecker_test_http( 'POST', $url, $args );
	}
	function shift8_woodpecker_test_http( $method, $url, $args ) {
		Env::$requests[] = compact( 'method', 'url', 'args' );
		if ( array() === Env::$responses ) {
			throw new \LogicException( 'Unexpected HTTP request: ' . $method . ' ' . $url );
		}
		return array_shift( Env::$responses );
	}
	function get_option( $name, $default = false ) {
		return Env::$options[ $name ] ?? $default;
	}
	function update_option( $name, $value ) {
		Env::$options[ $name ] = $value;
		return true;
	}
	function delete_option( $name ) {
		unset( Env::$options[ $name ] );
		return true;
	}
	function get_transient( $name ) {
		return Env::$transients[ $name ] ?? false;
	}
	function set_transient( $name, $value ) {
		Env::$transients[ $name ] = $value;
		return true;
	}
	function delete_transient( $name ) {
		unset( Env::$transients[ $name ] );
		return true;
	}
	function apply_filters( $hook, $value ) {
		return $value;
	}
	function add_action() {}
	function add_menu_page() {}
	function add_submenu_page() {}
	function add_management_page() {}
	function register_setting() {}
	function settings_fields() {}
	function settings_errors() {}
	function submit_button() {}
	function disabled( $disabled ) {
		echo $disabled ? 'disabled="disabled"' : '';
	}
	function add_settings_error( $setting, $code, $message ) {
		Env::$notices[] = array(
			'code'    => $code,
			'message' => $message,
		);
	}
	function admin_url( $path = '' ) {
		return 'https://wp.test/wp-admin/' . $path;
	}
	function wp_enqueue_style() {}
	function wp_enqueue_script() {}
	function wp_localize_script() {}
	function wp_create_nonce() {
		return 'nonce';
	}
	function wp_timezone() {
		return new \DateTimeZone( "UTC" );
	}
	function wp_timezone_string() {
		return "UTC";
	}
	function wp_date( $format, $timestamp = null ) {
		$timestamp = $timestamp ?? time();
		return gmdate( 'Y-m-d H:i', $timestamp );
	}
	function get_current_user_id() {
		return 7;
	}
	function wp_get_current_user() {
		return new WP_User();
	}
	function get_bloginfo() {
		return 'Example Site';
	}
	function current_user_can() {
		return Env::$can;
	}
	function check_ajax_referer() {
		return Env::$nonceValid;
	}
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $key ) );
	}
	function wp_die( $message = '' ) {
		throw new \RuntimeException( 'wp_die: ' . $message );
	}
	function shift8_woodpecker_test_json( $success, $data, $status ) {
		$response          = new JsonResponse();
		$response->success = $success;
		$response->data    = $data;
		$response->status  = (int) $status;
		throw $response;
	}
	function wp_send_json_success( $data = null, $status = 200 ) {
		shift8_woodpecker_test_json( true, $data, $status );
	}
	function wp_send_json_error( $data = null, $status = 400 ) {
		shift8_woodpecker_test_json( false, $data, $status );
	}
}
