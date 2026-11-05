<?php
/**
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Tests;

use Shift8\Woodpecker\Admin\Ajax;
use Shift8\Woodpecker\Admin\DeploymentPage;
use Shift8\Woodpecker\Admin\SettingsPage;
use Shift8\Woodpecker\Api\Client;
use Shift8\Woodpecker\Config;

class SecurityTest extends TestCase {

	private function ajax(): Ajax {
		return new Ajax( $this->deployment(), new Client( new Config() ), $this->scheduler(), $this->activity() );
	}

	public function testUnprivilegedUserCannotDeploy(): void {
		Env::$can = false;

		$response = $this->json( array( $this->ajax(), 'deploy' ) );

		$this->assertFalse( $response->success );
		$this->assertSame( 403, $response->status );
		$this->assertSame( array(), Env::$requests );
	}

	public function testInvalidNonceCannotDeploy(): void {
		Env::$nonceValid = false;

		$response = $this->json( array( $this->ajax(), 'deploy' ) );

		$this->assertFalse( $response->success );
		$this->assertSame( 403, $response->status );
		$this->assertSame( array(), Env::$requests );
	}

	/**
	 * @dataProvider endpoints
	 */
	public function testEveryEndpointChecksCapabilityAndNonce( string $method ): void {
		Env::$can = false;
		$this->assertSame( 403, $this->json( array( $this->ajax(), $method ) )->status );

		Env::$can        = true;
		Env::$nonceValid = false;
		$this->assertSame( 403, $this->json( array( $this->ajax(), $method ) )->status );
		$this->assertSame( array(), Env::$requests );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function endpoints(): array {
		return array(
			'deploy'     => array( 'deploy' ),
			'status'     => array( 'status' ),
			'cancel'     => array( 'cancel' ),
			'test'       => array( 'test' ),
			'schedule'   => array( 'schedule' ),
			'unschedule' => array( 'unschedule' ),
		);
	}

	public function testAuthorizedDeployIgnoresBrowserSuppliedTargets(): void {
		$_POST = array(
			'woodpecker_url' => 'https://evil.example',
			'repo_id'        => '999',
			'branch'         => 'evil',
			'workflow'       => 'evil',
			'token'          => 'evil',
		);
		Env::queue(
			200,
			array(
				'number' => 3,
				'status' => 'pending',
			)
		);

		$response = $this->json( array( $this->ajax(), 'deploy' ) );
		$_POST    = array();

		$this->assertTrue( $response->success );
		$this->assertSame( 'https://woodpecker.example.com/api/repos/123/pipelines', Env::$requests[0]['url'] );
		$body = json_decode( Env::$requests[0]['args']['body'], true );
		$this->assertSame( 'production', $body['branch'] );
		$this->assertSame( 'wordpress-deploy', $body['workflow'] );
	}

	public function testTokenNeverAppearsInBrowserResponses(): void {
		Env::queue(
			200,
			array(
				'number' => 3,
				'status' => 'pending',
			)
		);
		$deploy          = json_encode( $this->json( array( $this->ajax(), 'deploy' ) )->data );
		Env::$transients = array();
		Env::queue(
			200,
			array(
				'number' => 3,
				'status' => 'running',
			)
		);
		$status = json_encode( $this->json( array( $this->ajax(), 'status' ) )->data );
		Env::queue( 401, array( 'message' => self::TOKEN ) );
		$error = json_encode( $this->json( array( $this->ajax(), 'test' ) )->data );

		foreach ( array( $deploy, $status, $error ) as $payload ) {
			$this->assertStringNotContainsString( self::TOKEN, $payload );
		}
	}

	public function testSettingsPageNeverRendersToken(): void {
		ob_start();
		( new SettingsPage( new Config() ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( self::TOKEN, $html );
		$this->assertStringContainsString( 'Token is configured in wp-config.php', $html );
	}

	public function testDeploymentPageRendersTargetWithoutToken(): void {
		$page = new DeploymentPage( new Config(), $this->deployment(), $this->scheduler(), $this->activity() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( self::TOKEN, $html );
		$this->assertStringContainsString( 'production', $html );
		$this->assertStringContainsString( 'Deploy to Production', $html );
	}

	public function testDeploymentPageDeniesUnprivilegedUsers(): void {
		Env::$can = false;
		$page     = new DeploymentPage( new Config(), $this->deployment(), $this->scheduler(), $this->activity() );

		$this->expectException( \RuntimeException::class );
		$page->render();
	}
}
