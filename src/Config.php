<?php
/**
 * Resolved plugin configuration (constants override database values).
 *
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Config {

	public const OPTION = 'shift8_woodpecker_settings';

	public const DEFAULT_POLL_INTERVAL = 5;
	public const MIN_POLL_INTERVAL     = 3;
	public const MAX_POLL_INTERVAL     = 60;

	public const TOKEN_CONSTANT = 'SHIFT8_WOODPECKER_TOKEN';

	public const DEFAULT_MESSAGE = 'WordPress production deployment by {user}';

	/**
	 * Setting key => wp-config.php constant. The token is handled separately
	 * (constant only). The poll interval and message
	 * are cosmetic and intentionally not constant-managed.
	 */
	private const CONSTANTS = array(
		'url'      => 'SHIFT8_WOODPECKER_URL',
		'repo_id'  => 'SHIFT8_WOODPECKER_REPO_ID',
		'branch'   => 'SHIFT8_WOODPECKER_BRANCH',
		'workflow' => 'SHIFT8_WOODPECKER_WORKFLOW',
	);

	/**
	 * Whether a setting is supplied by a wp-config.php constant.
	 *
	 * @param string $key Setting key.
	 */
	public function isConstant( string $key ): bool {
		return isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] );
	}

	/**
	 * Raw setting value; the constant wins over the stored option.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	private function raw( string $key ) {
		if ( $this->isConstant( $key ) ) {
			return constant( self::CONSTANTS[ $key ] );
		}
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) && isset( $stored[ $key ] ) ? $stored[ $key ] : '';
	}

	public function baseUrl(): string {
		return self::normalizeUrl( (string) $this->raw( 'url' ) );
	}

	public function repoId(): int {
		$value = $this->raw( 'repo_id' );
		return is_scalar( $value ) && ctype_digit( (string) $value ) ? (int) $value : 0;
	}

	public function branch(): string {
		return self::cleanRef( $this->raw( 'branch' ) );
	}

	public function workflow(): string {
		return self::cleanRef( $this->raw( 'workflow' ) );
	}

	/**
	 * The API token. It can only come from wp-config.php so it is never stored
	 * in the database (dumps, backups, staging-to-production copies). Only the
	 * API client should ever call this.
	 */
	public function token(): string {
		$value = defined( self::TOKEN_CONSTANT ) ? constant( self::TOKEN_CONSTANT ) : '';
		return is_string( $value ) ? trim( $value ) : '';
	}

	public function hasToken(): bool {
		return '' !== $this->token();
	}

	public function messageTemplate(): string {
		$value = get_option( self::OPTION, array() );
		$value = is_array( $value ) && isset( $value['message'] ) ? trim( (string) $value['message'] ) : '';
		return '' === $value ? self::DEFAULT_MESSAGE : $value;
	}

	public function pollInterval(): int {
		$stored = get_option( self::OPTION, array() );
		$value  = is_array( $stored ) && isset( $stored['poll_interval'] ) ? (int) $stored['poll_interval'] : self::DEFAULT_POLL_INTERVAL;
		$value  = (int) apply_filters( 'shift8_woodpecker_poll_interval', $value );
		return max( self::MIN_POLL_INTERVAL, min( self::MAX_POLL_INTERVAL, $value ) );
	}

	/**
	 * Check that everything required to talk to Woodpecker is present.
	 *
	 * @return \WP_Error|true
	 */
	public function validate() {
		$missing = array();
		if ( '' === $this->baseUrl() ) {
			$missing[] = __( 'Woodpecker URL', 'shift8-woodpecker' );
		}
		if ( ! $this->hasToken() ) {
			$missing[] = __( 'SHIFT8_WOODPECKER_TOKEN constant in wp-config.php', 'shift8-woodpecker' );
		}
		if ( $this->repoId() < 1 ) {
			$missing[] = __( 'repository ID', 'shift8-woodpecker' );
		}
		if ( '' === $this->branch() ) {
			$missing[] = __( 'branch', 'shift8-woodpecker' );
		}
		if ( array() === $missing ) {
			return true;
		}
		return new \WP_Error(
			'shift8_woodpecker_config',
			sprintf(
				/* translators: %s: comma separated list of missing settings. */
				__( 'Woodpecker is not fully configured. Missing or invalid: %s.', 'shift8-woodpecker' ),
				implode( ', ', $missing )
			)
		);
	}

	/**
	 * Normalise a base URL to scheme://host[:port][/path] or '' when unusable.
	 *
	 * Only http(s) is accepted and credentials, query strings and fragments
	 * are dropped, so the API client can only ever talk to this one origin.
	 *
	 * @param string $url Candidate URL.
	 */
	public static function normalizeUrl( string $url ): string {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$host = strtolower( $parts['host'] );
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		if ( ! preg_match( '#^[A-Za-z0-9._~%/-]*$#', $path ) ) {
			return '';
		}
		return $scheme . '://' . $host . $port . $path;
	}

	/**
	 * Branch / workflow names: restrict to characters valid in git refs and
	 * file names so they cannot smuggle anything into the request path.
	 *
	 * @param mixed $value Candidate value.
	 */
	public static function cleanRef( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return preg_match( '#^[A-Za-z0-9._/-]{1,200}$#', $value ) ? $value : '';
	}
}
