<?php
/**
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Tests;

use Shift8\Woodpecker\Admin\SettingsPage;
use Shift8\Woodpecker\Api\Client;
use Shift8\Woodpecker\Config;

/**
 * The token constant cannot be undefined, so these run in separate processes without it.
 */
class NoTokenTest extends TestCase {

	protected bool $defineToken = false;

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testMissingTokenNeverMakesARequest(): void {
		$result = ( new Client( new Config() ) )->triggerPipeline( 'x' );

		$this->assertSame( 'shift8_woodpecker_config', $result->get_error_code() );
		$this->assertStringContainsString( 'SHIFT8_WOODPECKER_TOKEN', $result->get_error_message() );
		$this->assertSame( array(), Env::$requests );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testSettingsPageExplainsHowToConfigureTheToken(): void {
		ob_start();
		( new SettingsPage( new Config() ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No token configured', $html );
		$this->assertStringContainsString( "define( 'SHIFT8_WOODPECKER_TOKEN'", $html );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testTokenStoredInTheDatabaseIsIgnored(): void {
		$this->configure( array( 'token' => 'db-token' ) );

		$this->assertSame( '', ( new Config() )->token() );
	}
}
