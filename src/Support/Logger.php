<?php
/**
 * Minimal, opt-in logging that only records fixed, non-sensitive messages.
 *
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {

	/**
	 * Enabled by the SHIFT8_WOODPECKER_DEBUG constant or WP_DEBUG.
	 */
	public function enabled(): bool {
		if ( defined( 'SHIFT8_WOODPECKER_DEBUG' ) ) {
			return (bool) constant( 'SHIFT8_WOODPECKER_DEBUG' );
		}
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	/**
	 * Callers must only pass pipeline numbers and statuses, never request
	 * data, headers or settings values.
	 *
	 * @param string $message Log line.
	 */
	public function log( string $message ): void {
		if ( ! $this->enabled() ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in debug logging.
		error_log( '[shift8-woodpecker] ' . $message );
	}
}
