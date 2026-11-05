<?php
/**
 * Shared test case.
 *
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Tests;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Shift8\Woodpecker\Api\Client;
use Shift8\Woodpecker\Config;
use Shift8\Woodpecker\Deployment;
use Shift8\Woodpecker\Scheduler;
use Shift8\Woodpecker\Support\ActivityLog;
use Shift8\Woodpecker\Support\Logger;

abstract class TestCase extends PhpUnitTestCase {

	public const TOKEN = 'super-secret-token-123';

	/**
	 * The token only exists as a constant; classes testing its absence set this to false.
	 */
	protected bool $defineToken = true;

	protected function setUp(): void {
		parent::setUp();
		Env::reset();
		if ( $this->defineToken && ! defined( 'SHIFT8_WOODPECKER_TOKEN' ) ) {
			define( 'SHIFT8_WOODPECKER_TOKEN', self::TOKEN );
		}
		$this->configure();
	}

	/**
	 * @param array<string,mixed> $overrides Settings overriding the valid defaults.
	 */
	protected function configure( array $overrides = array() ): void {
		Env::$options[ Config::OPTION ] = array_merge(
			array(
				'url'           => 'https://woodpecker.example.com',
				'repo_id'       => 123,
				'branch'        => 'production',
				'workflow'      => 'wordpress-deploy',
				'message'       => '',
				'poll_interval' => 5,
			),
			$overrides
		);
	}

	protected function activity(): ActivityLog {
		return new ActivityLog( new \wpdb() );
	}

	protected function deployment(): Deployment {
		$config = new Config();
		return new Deployment( $config, new Client( $config ), new Logger(), $this->activity() );
	}

	protected function scheduler(): Scheduler {
		return new Scheduler( new Config(), $this->deployment(), $this->activity() );
	}

	/**
	 * Run a callable that ends in wp_send_json_*() and return that response.
	 */
	protected function json( callable $callback ): JsonResponse {
		try {
			$callback();
		} catch ( JsonResponse $response ) {
			return $response;
		}
		$this->fail( 'Expected a JSON response.' );
	}
}
