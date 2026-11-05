<?php
/**
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Tests;

use Shift8\Woodpecker\Admin\AdminPage;
use Shift8\Woodpecker\Admin\DeploymentPage;
use Shift8\Woodpecker\Admin\SettingsPage;
use Shift8\Woodpecker\Config;

class AdminPageTest extends TestCase {

	private function render( string $tab = '' ): string {
		$config = new Config();
		$page   = new AdminPage(
			new DeploymentPage( $config, $this->deployment(), $this->scheduler(), $this->activity() ),
			new SettingsPage( $config )
		);
		$_GET   = '' === $tab ? array() : array( 'tab' => $tab );
		ob_start();
		try {
			$page->render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
			$_GET = array();
		}
	}

	public function testDeploymentTabIsTheDefaultAndHoldsPushScheduleTestAndLog(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'nav-tab-active">Deployment', $html );
		foreach ( array( 's8w-deploy', 's8w-schedule', 's8w-test', 's8w-log' ) as $id ) {
			$this->assertStringContainsString( $id, $html );
		}
		$this->assertStringNotContainsString( 'options.php', $html );
	}

	public function testSettingsTabShowsOnlyTheSettingsForm(): void {
		$html = $this->render( 'settings' );

		$this->assertStringContainsString( 'nav-tab-active">Settings', $html );
		$this->assertStringContainsString( 'options.php', $html );
		$this->assertStringNotContainsString( 's8w-deploy', $html );
		$this->assertStringNotContainsString( self::TOKEN, $html );
	}

	public function testUnknownTabFallsBackToDeployment(): void {
		$this->assertStringContainsString( 's8w-deploy', $this->render( '"><script>' ) );
	}

	public function testPageDeniedWithoutCapability(): void {
		Env::$can = false;

		$this->expectException( \RuntimeException::class );
		$this->render();
	}

	private function adminPage(): AdminPage {
		$config = new Config();
		return new AdminPage(
			new DeploymentPage( $config, $this->deployment(), $this->scheduler(), $this->activity() ),
			new SettingsPage( $config )
		);
	}

	public function testCreatesTheSharedShift8MenuOnlyWhenAbsent(): void {
		unset( $GLOBALS['admin_page_hooks'] );
		$this->adminPage()->addMenu();
		$this->assertSame( array( 'top', 'sub' ), array_column( Env::$menus, 'type' ) );
		$this->assertSame( 'shift8-woodpecker', Env::$menus[1]['slug'] );
		$this->assertSame( 'shift8-settings', Env::$menus[1]['parent'] );

		Env::$menus                                     = array();
		$GLOBALS['admin_page_hooks']['shift8-settings'] = 'shift8'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- simulating another plugin's menu.
		$this->adminPage()->addMenu();
		$this->assertSame( array( 'sub' ), array_column( Env::$menus, 'type' ), 'Another Shift8 plugin already created the menu.' );
		unset( $GLOBALS['admin_page_hooks'] );
	}

	public function testAssetsOnlyLoadOnThisPluginsPage(): void {
		$page = $this->adminPage();

		$page->enqueue( 'dashboard' );
		$this->assertSame( array(), Env::$localized );
		$this->assertStringContainsString( 'img/shift8pluginicon.png', Env::$inlineCss[0], 'The menu icon is needed on every admin screen.' );

		$page->enqueue( 'shift8_page_shift8-woodpecker' );
		$this->assertArrayHasKey( 'shift8Woodpecker', Env::$localized );
	}
}
