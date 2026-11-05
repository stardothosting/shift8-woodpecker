<?php
/**
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Tests;

use Shift8\Woodpecker\Admin\SettingsPage;
use Shift8\Woodpecker\Config;

class SettingsTest extends TestCase {

	private function page(): SettingsPage {
		return new SettingsPage( new Config() );
	}

	/**
	 * @param array<string,mixed> $input Form input.
	 * @return array<string,mixed>
	 */
	private function sanitize( array $input ): array {
		return $this->page()->sanitize( $input );
	}

	public function testUrlIsNormalized(): void {
		$clean = $this->sanitize( array( 'url' => '  HTTPS://Woodpecker.Example.com:8443/ci/?x=1#frag ' ) );

		$this->assertSame( 'https://woodpecker.example.com:8443/ci', $clean['url'] );
	}

	/**
	 * @dataProvider badUrls
	 */
	public function testInvalidUrlIsRejectedAndPreviousKept( string $url ): void {
		$clean = $this->sanitize( array( 'url' => $url ) );

		$this->assertSame( 'https://woodpecker.example.com', $clean['url'] );
		$this->assertSame( 'shift8_woodpecker_url', Env::$notices[0]['code'] );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function badUrls(): array {
		return array(
			'file scheme' => array( 'file:///etc/passwd' ),
			'javascript'  => array( 'javascript:alert(1)' ),
			'credentials' => array( 'https://user:pass@woodpecker.example.com' ),
			'no host'     => array( 'https://' ),
			'plain text'  => array( 'not a url' ),
		);
	}

	public function testRepoIdMustBePositiveInteger(): void {
		$this->assertSame( 456, $this->sanitize( array( 'repo_id' => ' 456 ' ) )['repo_id'] );

		foreach ( array( 'abc', '-1', '0', '1.5', '12; DROP' ) as $bad ) {
			Env::$notices = array();
			$this->assertSame( 123, $this->sanitize( array( 'repo_id' => $bad ) )['repo_id'], $bad );
			$this->assertNotEmpty( Env::$notices, $bad );
		}
	}

	public function testBlankWorkflowIsAllowed(): void {
		$clean = $this->sanitize( array( 'workflow' => '   ' ) );

		$this->assertSame( '', $clean['workflow'] );
		$this->assertSame( array(), Env::$notices );
	}

	public function testBranchAndWorkflowRejectUnsafeCharacters(): void {
		$clean = $this->sanitize(
			array(
				'branch'   => 'prod?x=1',
				'workflow' => 'a b',
			)
		);

		$this->assertSame( 'production', $clean['branch'] );
		$this->assertSame( 'wordpress-deploy', $clean['workflow'] );
		$this->assertCount( 2, Env::$notices );
	}

	public function testTokenIsNeverStoredFromFormInput(): void {
		$clean = $this->sanitize( array( 'token' => 'typed-in-form' ) );

		$this->assertArrayNotHasKey( 'token', $clean );
		$this->assertStringNotContainsString( 'typed-in-form', json_encode( $clean ) );
	}

	public function testPollIntervalIsClamped(): void {
		$this->assertSame( Config::MIN_POLL_INTERVAL, $this->sanitize( array( 'poll_interval' => 0 ) )['poll_interval'] );
		$this->assertSame( Config::MAX_POLL_INTERVAL, $this->sanitize( array( 'poll_interval' => 9999 ) )['poll_interval'] );
		$this->assertSame( 10, $this->sanitize( array( 'poll_interval' => 10 ) )['poll_interval'] );
	}

	public function testMessageIsSanitizedAndBounded(): void {
		$clean = $this->sanitize( array( 'message' => '<b>Deploy</b> ' . str_repeat( 'x', 500 ) ) );

		$this->assertStringStartsWith( 'Deploy', $clean['message'] );
		$this->assertSame( 200, strlen( $clean['message'] ) );
	}

	public function testConfigValidationListsMissingValues(): void {
		$this->configure( array( 'url' => '' ) );

		$error = ( new Config() )->validate();

		$this->assertSame( 'shift8_woodpecker_config', $error->get_error_code() );
		$this->assertStringContainsString( 'Woodpecker URL', $error->get_error_message() );
	}

	public function testDefaultsApplyWhenNothingIsStored(): void {
		Env::$options = array();
		$config       = new Config();

		$this->assertSame( Config::DEFAULT_MESSAGE, $config->messageTemplate() );
		$this->assertSame( Config::DEFAULT_POLL_INTERVAL, $config->pollInterval() );
		$this->assertSame( 0, $config->repoId() );
	}
}
