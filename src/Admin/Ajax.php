<?php
/**
 * AJAX endpoints. The browser only submits an intent ("deploy", "status",
 * "test"); target URL, repository, branch, workflow and token are
 * never read from the request.
 *
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Admin;

use Shift8\Woodpecker\Api\Client;
use Shift8\Woodpecker\Deployment;
use Shift8\Woodpecker\Plugin;
use Shift8\Woodpecker\Scheduler;
use Shift8\Woodpecker\Support\ActivityLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ajax {

	public const NONCE_ACTION = 'shift8_woodpecker_ajax';

	private Deployment $deployment;
	private Client $client;
	private Scheduler $scheduler;
	private ActivityLog $activity;

	public function __construct( Deployment $deployment, Client $client, Scheduler $scheduler, ActivityLog $activity ) {
		$this->deployment = $deployment;
		$this->client     = $client;
		$this->scheduler  = $scheduler;
		$this->activity   = $activity;
	}

	public function register(): void {
		add_action( 'wp_ajax_shift8_woodpecker_deploy', array( $this, 'deploy' ) );
		add_action( 'wp_ajax_shift8_woodpecker_status', array( $this, 'status' ) );
		add_action( 'wp_ajax_shift8_woodpecker_schedule', array( $this, 'schedule' ) );
		add_action( 'wp_ajax_shift8_woodpecker_unschedule', array( $this, 'unschedule' ) );
		add_action( 'wp_ajax_shift8_woodpecker_test', array( $this, 'test' ) );
	}

	public function deploy(): void {
		$this->authorize( Plugin::deployCapability() );
		$this->respond( $this->deployment->trigger() );
	}

	public function status(): void {
		$this->authorize( Plugin::deployCapability() );
		$this->respond( $this->deployment->refresh() );
	}

	public function schedule(): void {
		$this->authorize( Plugin::deployCapability() );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
		$input     = isset( $_POST['run_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['run_at'] ) ) : '';
		$timestamp = $this->scheduler->parse( $input );
		if ( is_wp_error( $timestamp ) ) {
			$this->fail( $timestamp->get_error_message(), 400 );
		}
		$result = $this->scheduler->schedule( get_current_user_id(), $timestamp );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message(), 400 );
		}
		$this->respond( $this->deployment->refresh() );
	}

	public function unschedule(): void {
		$this->authorize( Plugin::deployCapability() );
		$result = $this->scheduler->cancel( get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message(), 400 );
		}
		$this->respond( $this->deployment->refresh() );
	}

	public function test(): void {
		$this->authorize( Plugin::settingsCapability() );
		$result = $this->client->testConnection();
		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message(), 400 );
		}
		wp_send_json_success( array( 'messages' => $result ) );
	}

	/**
	 * Capability first, then nonce, on every request.
	 *
	 * @param string $capability Required capability.
	 */
	private function authorize( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			$this->fail( __( 'You are not allowed to do that.', 'shift8-woodpecker-ci' ), 403 );
		}
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			$this->fail( __( 'Your session has expired. Reload the page and try again.', 'shift8-woodpecker-ci' ), 403 );
		}
	}

	/**
	 * @param array<string,mixed>|\WP_Error|null $result Deployment result.
	 */
	private function respond( $result ): void {
		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message(), 400 );
		}
		wp_send_json_success(
			array(
				'pipeline' => $result,
				'schedule' => $this->scheduler->describe( $this->scheduler->current() ),
				'log'      => $this->activity->recent( 50 ),
			)
		);
	}

	private function fail( string $message, int $status ): void {
		wp_send_json_error( array( 'message' => $message ), $status );
	}
}
