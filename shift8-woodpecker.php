<?php
/**
 * Plugin Name: Shift8 Woodpecker CI
 * Plugin URI: https://github.com/stardothosting/shift8-woodpecker
 * Description: Trigger and monitor Woodpecker CI deployments from the WordPress admin, server-side.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Shift8 Web
 * Author URI: https://www.shift8web.ca
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: shift8-woodpecker-ci
 *
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SHIFT8_WOODPECKER_PLUGIN_VERSION', '1.1.0' );
define( 'SHIFT8_WOODPECKER_PLUGIN_FILE', __FILE__ );
define( 'SHIFT8_WOODPECKER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHIFT8_WOODPECKER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// The plugin has no runtime dependencies, so a tiny PSR-4 loader avoids shipping vendor/.
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$file     = SHIFT8_WOODPECKER_PLUGIN_DIR . 'src/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		global $wpdb;
		( new Support\ActivityLog( $wpdb ) )->install();
	}
);

// A pending schedule must not outlive the plugin's ability to run it.
register_deactivation_hook( __FILE__, array( Scheduler::class, 'clearAll' ) );

add_action(
	'plugins_loaded',
	static function () {
		( new Plugin() )->register();
	}
);
