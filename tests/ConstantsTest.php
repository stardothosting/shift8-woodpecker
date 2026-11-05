<?php
/**
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Tests;

use Shift8\Woodpecker\Admin\SettingsPage;
use Shift8\Woodpecker\Config;

/**
 * Constants cannot be undefined, so each test runs in its own process.
 */
class ConstantsTest extends TestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testConstantsOverrideDatabaseSettings(): void {
		define( 'SHIFT8_WOODPECKER_URL', 'https://ci.constant.test/' );
		define( 'SHIFT8_WOODPECKER_REPO_ID', 999 );
		define( 'SHIFT8_WOODPECKER_BRANCH', 'main' );
		define( 'SHIFT8_WOODPECKER_WORKFLOW', 'from-constant' );

		$config = new Config();

		$this->assertSame( 'https://ci.constant.test', $config->baseUrl() );
		$this->assertSame( self::TOKEN, $config->token() );
		$this->assertSame( 999, $config->repoId() );
		$this->assertSame( 'main', $config->branch() );
		$this->assertSame( 'from-constant', $config->workflow() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testConstantManagedFieldsAreReadOnly(): void {
		define( 'SHIFT8_WOODPECKER_BRANCH', 'main' );

		ob_start();
		( new SettingsPage( new Config() ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( self::TOKEN, $html );
		$this->assertStringContainsString( 'Managed by wp-config.php', $html );
		$this->assertStringContainsString( 'disabled="disabled"', $html );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testFormInputCannotOverrideConstantManagedValues(): void {
		define( 'SHIFT8_WOODPECKER_BRANCH', 'main' );

		$clean = ( new SettingsPage( new Config() ) )->sanitize( array( 'branch' => 'hacked' ) );

		$this->assertSame( 'production', $clean['branch'], 'The stored value is left untouched; the constant keeps winning at read time.' );
		$this->assertSame( 'main', ( new Config() )->branch() );
	}

	public function testIsNotConstantWhenUndefined(): void {
		$this->assertFalse( ( new Config() )->isConstant( 'repo_id' ) );
	}
}
