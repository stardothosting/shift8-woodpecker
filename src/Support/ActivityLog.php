<?php
/**
 * Audit trail of who started, scheduled or cancelled a deployment and when.
 *
 * A table is used instead of an option so the trail is never silently
 * truncated and rows cannot race each other.
 *
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ActivityLog {

	public const DB_VERSION_OPTION = 'shift8_woodpecker_db_version';
	public const DB_VERSION        = '1';

	public const DEPLOY             = 'deploy';
	public const DEPLOY_FINISHED    = 'deploy_finished';
	public const DEPLOY_FAILED      = 'deploy_failed';
	public const SCHEDULED          = 'scheduled';
	public const SCHEDULE_CANCELLED = 'schedule_cancelled';
	public const SCHEDULED_RUN      = 'scheduled_run';
	public const SCHEDULED_FAILED   = 'scheduled_failed';
	public const SCHEDULED_MISSED   = 'scheduled_missed';

	private \wpdb $db;

	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	public function table(): string {
		return $this->db->prefix . 'shift8_woodpecker_log';
	}

	/**
	 * Create or update the table (activation and version bumps).
	 */
	public function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $this->table();
		$charset = $this->db->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_login varchar(60) NOT NULL DEFAULT '',
				event varchar(32) NOT NULL,
				pipeline_number bigint(20) unsigned NULL,
				scheduled_for datetime NULL,
				details varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id)
			) {$charset};"
		);
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	public function maybeInstall(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			$this->install();
		}
	}

	public function drop(): void {
		$this->db->query( 'DROP TABLE IF EXISTS ' . $this->table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- fixed internal table name.
		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Record an event. The login is stored as a snapshot so the trail stays
	 * meaningful if the user is later renamed or deleted. Timestamps are UTC.
	 *
	 * @param int                 $user_id User who acted (0 for the system).
	 * @param string              $event   One of the event constants.
	 * @param array<string,mixed> $context Optional: pipeline, scheduled_for (unix), details.
	 */
	public function record( int $user_id, string $event, array $context = array() ): void {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		$this->db->insert(
			$this->table(),
			array(
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
				'user_id'         => $user_id,
				'user_login'      => $user ? (string) $user->user_login : '',
				'event'           => $event,
				'pipeline_number' => isset( $context['pipeline'] ) ? (int) $context['pipeline'] : null,
				'scheduled_for'   => isset( $context['scheduled_for'] ) ? gmdate( 'Y-m-d H:i:s', (int) $context['scheduled_for'] ) : null,
				'details'         => mb_substr( sanitize_text_field( (string) ( $context['details'] ?? '' ) ), 0, 255 ),
			)
		);
	}

	/**
	 * Newest entries, formatted for display in the site timezone.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,string>>
	 */
	public function recent( int $limit = 50 ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( 'SELECT * FROM ' . $this->table() . ' ORDER BY id DESC LIMIT %d', max( 1, $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed internal table name.
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'date'    => $this->format( (string) $row['created_at'] ),
				'user'    => '' !== (string) $row['user_login'] ? (string) $row['user_login'] : __( 'system', 'shift8-deployment-manager-for-woodpecker-ci' ),
				'action'  => self::label( (string) $row['event'] ),
				'details' => $this->details( $row ),
			);
		}
		return $out;
	}

	public static function label( string $event ): string {
		$labels = array(
			self::DEPLOY             => __( 'Started deployment', 'shift8-deployment-manager-for-woodpecker-ci' ),
			self::DEPLOY_FINISHED    => __( 'Deployment finished', 'shift8-deployment-manager-for-woodpecker-ci' ),
			self::DEPLOY_FAILED      => __( 'Deployment could not be started', 'shift8-deployment-manager-for-woodpecker-ci' ),
			self::SCHEDULED          => __( 'Scheduled deployment', 'shift8-deployment-manager-for-woodpecker-ci' ),
			self::SCHEDULE_CANCELLED => __( 'Cancelled scheduled deployment', 'shift8-deployment-manager-for-woodpecker-ci' ),
			self::SCHEDULED_RUN      => __( 'Scheduled deployment started', 'shift8-deployment-manager-for-woodpecker-ci' ),
			self::SCHEDULED_FAILED   => __( 'Scheduled deployment could not be started', 'shift8-deployment-manager-for-woodpecker-ci' ),
			self::SCHEDULED_MISSED   => __( 'Scheduled deployment missed', 'shift8-deployment-manager-for-woodpecker-ci' ),
		);
		return $labels[ $event ] ?? $event;
	}

	/**
	 * @param array<string,mixed> $row Database row.
	 */
	private function details( array $row ): string {
		$parts = array();
		if ( ! empty( $row['pipeline_number'] ) ) {
			$parts[] = '#' . (int) $row['pipeline_number'];
		}
		if ( ! empty( $row['scheduled_for'] ) ) {
			/* translators: %s: date and time. */
			$parts[] = sprintf( __( 'for %s', 'shift8-deployment-manager-for-woodpecker-ci' ), $this->format( (string) $row['scheduled_for'] ) );
		}
		if ( '' !== (string) $row['details'] ) {
			$parts[] = (string) $row['details'];
		}
		return implode( ' – ', $parts );
	}

	private function format( string $utc ): string {
		$timestamp = strtotime( $utc . ' UTC' );
		return false === $timestamp ? $utc : (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
