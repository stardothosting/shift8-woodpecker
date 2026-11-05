<?php
/**
 * Immutable view of a Woodpecker pipeline, treated as untrusted input.
 *
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pipeline {

	public const PENDING   = 'pending';
	public const RUNNING   = 'running';
	public const SUCCESS   = 'success';
	public const FAILURE   = 'failure';
	public const CANCELLED = 'cancelled';
	public const UNKNOWN   = 'unknown';

	private int $number;
	private string $rawStatus;
	private int $started;
	private int $finished;

	public function __construct( int $number, string $raw_status, int $started = 0, int $finished = 0 ) {
		$this->number    = $number;
		$this->rawStatus = $raw_status;
		$this->started   = $started;
		$this->finished  = $finished;
	}

	/**
	 * Build from a decoded Woodpecker response.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return self|null Null when the payload is not a usable pipeline.
	 */
	public static function fromArray( $data ): ?self {
		if ( ! is_array( $data ) || ! isset( $data['number'] ) || ! is_numeric( $data['number'] ) || (int) $data['number'] < 1 ) {
			return null;
		}
		$status = isset( $data['status'] ) && is_string( $data['status'] ) ? strtolower( $data['status'] ) : '';
		return new self(
			(int) $data['number'],
			preg_replace( '/[^a-z_]/', '', $status ),
			isset( $data['started'] ) ? max( 0, (int) $data['started'] ) : 0,
			isset( $data['finished'] ) ? max( 0, (int) $data['finished'] ) : 0
		);
	}

	/**
	 * Collapse Woodpecker's status vocabulary into a stable set so new
	 * upstream statuses degrade to "unknown" instead of breaking the UI.
	 *
	 * @param string $status Raw Woodpecker status.
	 */
	public static function normalizeStatus( string $status ): string {
		switch ( strtolower( $status ) ) {
			case 'pending':
			case 'blocked':
			case 'created':
				return self::PENDING;
			case 'running':
			case 'started':
				return self::RUNNING;
			case 'success':
				return self::SUCCESS;
			case 'failure':
			case 'error':
				return self::FAILURE;
			case 'killed':
			case 'canceled':
			case 'cancelled':
			case 'declined':
			case 'skipped':
				return self::CANCELLED;
			default:
				return self::UNKNOWN;
		}
	}

	public function number(): int {
		return $this->number;
	}

	public function rawStatus(): string {
		return $this->rawStatus;
	}

	public function state(): string {
		return self::normalizeStatus( $this->rawStatus );
	}

	public function isActive(): bool {
		return in_array( $this->state(), array( self::PENDING, self::RUNNING ), true );
	}

	public function isFinal(): bool {
		return in_array( $this->state(), array( self::SUCCESS, self::FAILURE, self::CANCELLED ), true );
	}

	public function started(): int {
		return $this->started;
	}

	public function finished(): int {
		return $this->finished;
	}
}
