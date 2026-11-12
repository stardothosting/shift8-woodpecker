<?php
/**
 * Delayed deployments.
 *
 * Woodpecker has no delayed-start option: its pipeline API starts
 * immediately and its cron jobs are recurring and fire with the "cron" event
 * rather than "manual". The schedule is therefore held in WordPress (one
 * WP-Cron single event) and the normal manual pipeline is triggered when it is
 * due. Only one schedule can be pending at a time.
 *
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker;

use Shift8\Woodpecker\Support\ActivityLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Scheduler {

	public const HOOK   = 'shift8_woodpecker_run_scheduled';
	public const OPTION = 'shift8_woodpecker_scheduled';

	public const MIN_LEAD = 60;
	public const MAX_LEAD = 2592000; // 30 days.

	// A schedule that WP-Cron could not run on time is skipped rather than deploying at a surprising moment.
	private const DEFAULT_GRACE = 1800;

	private Deployment $deployment;
	private ActivityLog $activity;
	private Config $config;

	public function __construct( Config $config, Deployment $deployment, ActivityLog $activity ) {
		$this->config     = $config;
		$this->deployment = $deployment;
		$this->activity   = $activity;
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Convert the value of a datetime-local input (site timezone) to a timestamp.
	 *
	 * @param string $input Value such as 2026-11-05T03:00.
	 * @return int|\WP_Error
	 */
	public function parse( string $input ) {
		$date = preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $input ) ? \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $input, wp_timezone() ) : false;
		if ( false === $date ) {
			return new \WP_Error( 'shift8_woodpecker_schedule_invalid', __( 'Choose a valid date and time.', 'shift8-deployment-manager-for-woodpecker-ci' ) );
		}
		return $date->getTimestamp();
	}

	/**
	 * Schedule a deployment.
	 *
	 * @param int $user_id   User scheduling it.
	 * @param int $timestamp Unix time to start.
	 * @return array<string,mixed>|\WP_Error Schedule description.
	 */
	public function schedule( int $user_id, int $timestamp ) {
		$valid = $this->config->validate();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$now = time();
		if ( $timestamp < $now + self::MIN_LEAD ) {
			return new \WP_Error( 'shift8_woodpecker_schedule_past', __( 'Choose a time at least a minute in the future.', 'shift8-deployment-manager-for-woodpecker-ci' ) );
		}
		if ( $timestamp > $now + self::MAX_LEAD ) {
			return new \WP_Error( 'shift8_woodpecker_schedule_far', __( 'Deployments can be scheduled at most 30 days ahead.', 'shift8-deployment-manager-for-woodpecker-ci' ) );
		}
		if ( null !== $this->current() ) {
			return new \WP_Error( 'shift8_woodpecker_schedule_exists', __( 'A deployment is already scheduled. Cancel it first to schedule another.', 'shift8-deployment-manager-for-woodpecker-ci' ) );
		}

		if ( false === wp_schedule_single_event( $timestamp, self::HOOK, array( $timestamp ) ) ) {
			return new \WP_Error( 'shift8_woodpecker_schedule_failed', __( 'WordPress could not schedule the deployment.', 'shift8-deployment-manager-for-woodpecker-ci' ) );
		}
		update_option(
			self::OPTION,
			array(
				'timestamp' => $timestamp,
				'user_id'   => $user_id,
			),
			false
		);
		$this->activity->record( $user_id, ActivityLog::SCHEDULED, array( 'scheduled_for' => $timestamp ) );

		return $this->describe( $this->current() );
	}

	/**
	 * @param int $user_id User cancelling.
	 * @return true|\WP_Error
	 */
	public function cancel( int $user_id ) {
		$pending = $this->current();
		if ( null === $pending ) {
			return new \WP_Error( 'shift8_woodpecker_nothing_scheduled', __( 'There is no scheduled deployment to cancel.', 'shift8-deployment-manager-for-woodpecker-ci' ) );
		}
		wp_unschedule_event( $pending['timestamp'], self::HOOK, array( $pending['timestamp'] ) );
		delete_option( self::OPTION );
		$this->activity->record( $user_id, ActivityLog::SCHEDULE_CANCELLED, array( 'scheduled_for' => $pending['timestamp'] ) );
		return true;
	}

	/**
	 * Cron callback: start the scheduled deployment.
	 *
	 * @param int $timestamp Timestamp the event was scheduled for.
	 */
	public function run( $timestamp = 0 ): void {
		$pending = $this->current();
		// Ignore events that were cancelled or replaced after being queued.
		if ( null === $pending || $pending['timestamp'] !== (int) $timestamp ) {
			return;
		}
		delete_option( self::OPTION );

		$user_id = $pending['user_id'];
		$context = array( 'scheduled_for' => $pending['timestamp'] );

		// Authorization happened when scheduling; make sure it still holds.
		if ( ! user_can( $user_id, Plugin::deployCapability() ) ) {
			$this->activity->record( $user_id, ActivityLog::SCHEDULED_FAILED, $context + array( 'details' => __( 'The user who scheduled this is no longer authorized.', 'shift8-deployment-manager-for-woodpecker-ci' ) ) );
			return;
		}

		$grace = (int) apply_filters( 'shift8_woodpecker_schedule_grace', self::DEFAULT_GRACE );
		if ( time() > $pending['timestamp'] + $grace ) {
			$this->activity->record( $user_id, ActivityLog::SCHEDULED_MISSED, $context + array( 'details' => __( 'WP-Cron did not run on time.', 'shift8-deployment-manager-for-woodpecker-ci' ) ) );
			return;
		}

		$this->deployment->trigger( $user_id, true );
	}

	/**
	 * @return array{timestamp:int,user_id:int}|null
	 */
	public function current(): ?array {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || empty( $stored['timestamp'] ) ) {
			return null;
		}
		return array(
			'timestamp' => (int) $stored['timestamp'],
			'user_id'   => isset( $stored['user_id'] ) ? (int) $stored['user_id'] : 0,
		);
	}

	/**
	 * Browser-safe description of the pending schedule.
	 *
	 * @param array{timestamp:int,user_id:int}|null $pending Pending schedule.
	 * @return array<string,mixed>|null
	 */
	public function describe( ?array $pending ): ?array {
		if ( null === $pending ) {
			return null;
		}
		$user = get_userdata( $pending['user_id'] );
		return array(
			'timestamp' => $pending['timestamp'],
			'when'      => (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $pending['timestamp'] ),
			'user'      => $user ? (string) $user->user_login : '',
			'overdue'   => $pending['timestamp'] < time(),
		);
	}

	/**
	 * Remove the pending schedule and its cron event (deactivation / uninstall).
	 */
	public static function clearAll(): void {
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( self::OPTION );
	}
}
