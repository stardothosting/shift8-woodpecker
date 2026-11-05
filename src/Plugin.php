<?php
/**
 * Plugin wiring.
 *
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker;

use Shift8\Woodpecker\Admin\AdminPage;
use Shift8\Woodpecker\Admin\Ajax;
use Shift8\Woodpecker\Admin\DeploymentPage;
use Shift8\Woodpecker\Admin\SettingsPage;
use Shift8\Woodpecker\Api\Client;
use Shift8\Woodpecker\Support\ActivityLog;
use Shift8\Woodpecker\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	public function register(): void {
		global $wpdb;

		$config     = new Config();
		$client     = new Client( $config );
		$activity   = new ActivityLog( $wpdb );
		$deployment = new Deployment( $config, $client, new Logger(), $activity );
		$scheduler  = new Scheduler( $config, $deployment, $activity );

		// Must run outside the admin too: WP-Cron fires the scheduled deployment.
		$scheduler->register();

		if ( ! is_admin() ) {
			return;
		}

		$activity->maybeInstall();
		$settings = new SettingsPage( $config );
		$settings->register();
		( new AdminPage( new DeploymentPage( $config, $deployment, $scheduler, $activity ), $settings ) )->register();
		( new Ajax( $deployment, $client, $scheduler, $activity ) )->register();
	}

	/**
	 * Capability required to trigger, monitor and cancel deployments.
	 */
	public static function deployCapability(): string {
		$capability = apply_filters( 'shift8_woodpecker_deploy_capability', 'manage_options' );
		return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
	}

	/**
	 * Settings (and the credentials stored there) are always admin-only.
	 */
	public static function settingsCapability(): string {
		return 'manage_options';
	}
}
