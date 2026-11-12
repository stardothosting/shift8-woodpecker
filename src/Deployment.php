<?php
/**
 * Deployment use-cases: trigger and track. No deployment logic lives
 * here; Woodpecker and its pipeline script do the actual work.
 *
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker;

use Shift8\Woodpecker\Api\Client;
use Shift8\Woodpecker\Api\Pipeline;
use Shift8\Woodpecker\Support\ActivityLog;
use Shift8\Woodpecker\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deployment {

	public const STATE_OPTION = 'shift8_woodpecker_last_pipeline';
	public const LOCK_KEY     = 'shift8_woodpecker_trigger_lock';

	// Only suppresses double-clicks; Woodpecker's own concurrency limit serialises deployments.
	private const LOCK_TTL = 10;

	private Config $config;
	private Client $client;
	private Logger $logger;
	private ActivityLog $activity;

	public function __construct( Config $config, Client $client, Logger $logger, ActivityLog $activity ) {
		$this->config   = $config;
		$this->client   = $client;
		$this->logger   = $logger;
		$this->activity = $activity;
	}

	/**
	 * Start a deployment and record who started it.
	 *
	 * @param int  $user_id   User on whose behalf this runs (0 = current user).
	 * @param bool $scheduled Whether this is the run of a scheduled deployment.
	 * @return array<string,mixed>|\WP_Error Public pipeline description.
	 */
	public function trigger( int $user_id = 0, bool $scheduled = false ) {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		$result  = $this->start( $user_id );

		if ( is_wp_error( $result ) ) {
			// A rejected double-click is noise, not an event worth auditing.
			if ( 'shift8_woodpecker_locked' !== $result->get_error_code() ) {
				$this->activity->record(
					$user_id,
					$scheduled ? ActivityLog::SCHEDULED_FAILED : ActivityLog::DEPLOY_FAILED,
					array( 'details' => $result->get_error_message() )
				);
			}
			return $result;
		}

		$this->activity->record(
			$user_id,
			$scheduled ? ActivityLog::SCHEDULED_RUN : ActivityLog::DEPLOY,
			array( 'pipeline' => $result['number'] )
		);
		return $result;
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private function start( int $user_id ) {
		$valid = $this->config->validate();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( get_transient( self::LOCK_KEY ) ) {
			return new \WP_Error( 'shift8_woodpecker_locked', __( 'A deployment request was just submitted. Please wait a few seconds.', 'shift8-deployment-manager-for-woodpecker-ci' ) );
		}

		$current = $this->refresh();
		if ( is_wp_error( $current ) ) {
			if ( 'shift8_woodpecker_not_found' !== $current->get_error_code() ) {
				return $current;
			}
			// The tracked pipeline no longer exists upstream; stop tracking it.
			delete_option( self::STATE_OPTION );
		} elseif ( null !== $current && $current['active'] ) {
			return new \WP_Error(
				'shift8_woodpecker_active',
				sprintf(
					/* translators: 1: pipeline number, 2: pipeline status. */
					__( 'Pipeline #%1$d is still %2$s. Wait for it to finish before starting another.', 'shift8-deployment-manager-for-woodpecker-ci' ),
					$current['number'],
					$current['state']
				)
			);
		}

		set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

		$pipeline = $this->client->triggerPipeline( $this->message( $user_id ) );
		if ( is_wp_error( $pipeline ) ) {
			delete_transient( self::LOCK_KEY );
			return $pipeline;
		}

		$state = array(
			'number'       => $pipeline->number(),
			'status'       => $pipeline->rawStatus(),
			'started'      => $pipeline->started(),
			'finished'     => $pipeline->finished(),
			'triggered_at' => time(),
			'user_id'      => $user_id,
		);
		update_option( self::STATE_OPTION, $state, false );
		$this->logger->log( sprintf( 'Woodpecker pipeline #%d triggered.', $pipeline->number() ) );

		return $this->describe( $state );
	}

	/**
	 * Re-query the tracked pipeline unless it already finished.
	 *
	 * @return array<string,mixed>|\WP_Error|null Null when nothing is tracked.
	 */
	public function refresh() {
		$state = $this->last();
		if ( null === $state ) {
			return null;
		}
		$known = Pipeline::normalizeStatus( $state['status'] );
		if ( Pipeline::PENDING !== $known && Pipeline::RUNNING !== $known ) {
			return $this->describe( $state );
		}

		// Always the stored pipeline number, never one supplied by the browser.
		$pipeline = $this->client->getPipeline( $state['number'] );
		if ( is_wp_error( $pipeline ) ) {
			return $pipeline;
		}

		$previous = $state['status'];
		if ( $pipeline->rawStatus() !== $previous ) {
			$state['status']   = $pipeline->rawStatus();
			$state['started']  = $pipeline->started();
			$state['finished'] = $pipeline->finished();
			update_option( self::STATE_OPTION, $state, false );

			// The stored status changes exactly once when the pipeline stops, so the
			// finish is logged once, attributed to whoever started the deployment.
			if ( ! $pipeline->isActive() ) {
				$this->activity->record(
					$state['user_id'],
					ActivityLog::DEPLOY_FINISHED,
					array(
						'pipeline' => $state['number'],
						'details'  => $pipeline->state(),
					)
				);
			}
			$this->logger->log(
				$pipeline->isFinal()
					? sprintf( 'Woodpecker pipeline #%d completed: %s.', $state['number'], $pipeline->state() )
					: sprintf( 'Woodpecker pipeline #%d status changed: %s -> %s.', $state['number'], Pipeline::normalizeStatus( $previous ), $pipeline->state() )
			);
		}
		return $this->describe( $state );
	}

	/**
	 * Stored state of the last triggered pipeline.
	 *
	 * @return array{number:int,status:string,started:int,finished:int,triggered_at:int,user_id:int}|null
	 */
	public function last(): ?array {
		$state = get_option( self::STATE_OPTION );
		if ( ! is_array( $state ) || empty( $state['number'] ) ) {
			return null;
		}
		return array(
			'number'       => (int) $state['number'],
			'status'       => isset( $state['status'] ) ? (string) $state['status'] : '',
			'started'      => isset( $state['started'] ) ? (int) $state['started'] : 0,
			'finished'     => isset( $state['finished'] ) ? (int) $state['finished'] : 0,
			'triggered_at' => isset( $state['triggered_at'] ) ? (int) $state['triggered_at'] : 0,
			'user_id'      => isset( $state['user_id'] ) ? (int) $state['user_id'] : 0,
		);
	}

	/**
	 * Presentation data. Everything here is safe to hand to the browser;
	 * the token and Woodpecker's raw payload never pass through.
	 *
	 * @param array<string,mixed> $state Stored state.
	 * @return array<string,mixed>
	 */
	public function describe( array $state ): array {
		$normalized = Pipeline::normalizeStatus( $state['status'] );
		return array(
			'number' => (int) $state['number'],
			'state'  => $normalized,
			'active' => Pipeline::PENDING === $normalized || Pipeline::RUNNING === $normalized,
		);
	}

	private function message( int $user_id ): string {
		$user    = get_userdata( $user_id );
		$message = strtr(
			$this->config->messageTemplate(),
			array(
				'{user}' => $user ? $user->display_name : 'unknown',
				'{site}' => get_bloginfo( 'name' ),
			)
		);
		return mb_substr( sanitize_text_field( $message ), 0, 200 );
	}
}
