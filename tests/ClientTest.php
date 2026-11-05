<?php
/**
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Tests;

use Shift8\Woodpecker\Api\Client;
use Shift8\Woodpecker\Api\Pipeline;
use Shift8\Woodpecker\Config;

class ClientTest extends TestCase {

	private function client(): Client {
		return new Client( new Config() );
	}

	public function testTriggerBuildsAuthenticatedManualPipelineRequest(): void {
		Env::queue(
			200,
			array(
				'number' => 184,
				'status' => 'pending',
			)
		);

		$pipeline = $this->client()->triggerPipeline( 'Deploy' );

		$this->assertInstanceOf( Pipeline::class, $pipeline );
		$this->assertSame( 184, $pipeline->number() );
		$this->assertCount( 1, Env::$requests );
		$request = Env::$requests[0];
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'https://woodpecker.example.com/api/repos/123/pipelines', $request['url'] );
		$this->assertSame( 'Bearer ' . self::TOKEN, $request['args']['headers']['Authorization'] );
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertGreaterThanOrEqual( 10, $request['args']['timeout'] );
		$this->assertLessThanOrEqual( 15, $request['args']['timeout'] );
		$this->assertSame(
			'{"branch":"production","message":"Deploy","variables":{},"workflow":"wordpress-deploy"}',
			$request['args']['body']
		);
	}

	public function testWorkflowIsOmittedWhenBlank(): void {
		$this->configure( array( 'workflow' => '' ) );
		Env::queue(
			200,
			array(
				'number' => 1,
				'status' => 'pending',
			)
		);

		$this->client()->triggerPipeline( 'Deploy' );

		$body = json_decode( Env::$requests[0]['args']['body'], true );
		$this->assertArrayNotHasKey( 'workflow', $body );
		$this->assertSame( 'production', $body['branch'] );
	}

	public function testGetPipelineUsesExactNumber(): void {
		Env::queue(
			200,
			array(
				'number'  => 42,
				'status'  => 'running',
				'started' => 100,
			)
		);

		$pipeline = $this->client()->getPipeline( 42 );

		$this->assertSame( 'GET', Env::$requests[0]['method'] );
		$this->assertSame( 'https://woodpecker.example.com/api/repos/123/pipelines/42', Env::$requests[0]['url'] );
		$this->assertSame( Pipeline::RUNNING, $pipeline->state() );
		$this->assertSame( 100, $pipeline->started() );
	}

	public function testGetLatestPipeline(): void {
		Env::queue(
			200,
			array(
				'number' => 9,
				'status' => 'success',
			)
		);

		$this->client()->getLatestPipeline();

		$this->assertSame( 'https://woodpecker.example.com/api/repos/123/pipelines/latest', Env::$requests[0]['url'] );
	}

	public function testCancelPostsToCancelEndpoint(): void {
		Env::queue( 200, array() );

		$result = $this->client()->cancelPipeline( 42 );

		$this->assertTrue( $result );
		$this->assertSame( 'POST', Env::$requests[0]['method'] );
		$this->assertSame( 'https://woodpecker.example.com/api/repos/123/pipelines/42/cancel', Env::$requests[0]['url'] );
	}

	public function testGetWorkflowsAcceptsObjectsAndStrings(): void {
		Env::queue( 200, array( array( 'name' => 'wordpress-deploy' ), 'other', array( 'id' => 1 ) ) );

		$names = $this->client()->getWorkflows();

		$this->assertSame( array( 'wordpress-deploy', 'other' ), $names );
		$this->assertSame( 'https://woodpecker.example.com/api/repos/123/workflows?branch=production', Env::$requests[0]['url'] );
	}

	public function testBranchIsUrlEncodedInWorkflowQuery(): void {
		$this->configure( array( 'branch' => 'release/1.0' ) );
		Env::queue( 200, array() );

		$this->client()->getWorkflows();

		$this->assertStringEndsWith( '?branch=release%2F1.0', Env::$requests[0]['url'] );
	}

	/**
	 * @dataProvider errorStatuses
	 */
	public function testNon2xxResponsesBecomeSpecificErrors( int $status, string $code ): void {
		Env::queue( $status, array( 'message' => 'nope' ) );

		$result = $this->client()->getPipeline( 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/**
	 * @return array<string,array{int,string}>
	 */
	public function errorStatuses(): array {
		return array(
			'unauthenticated' => array( 401, 'shift8_woodpecker_auth' ),
			'forbidden'       => array( 403, 'shift8_woodpecker_forbidden' ),
			'not found'       => array( 404, 'shift8_woodpecker_not_found' ),
			'server error'    => array( 500, 'shift8_woodpecker_status_failed' ),
		);
	}

	public function testRejectedTriggerIsReportedAsRejected(): void {
		Env::queue( 400, array( 'message' => 'pipeline blocked' ) );

		$result = $this->client()->triggerPipeline( 'x' );

		$this->assertSame( 'shift8_woodpecker_trigger_rejected', $result->get_error_code() );
		$this->assertStringContainsString( 'pipeline blocked', $result->get_error_message() );
	}

	public function testMalformedJsonIsAnInvalidResponse(): void {
		Env::queue( 200, '<html>proxy error</html>' );

		$result = $this->client()->getPipeline( 1 );

		$this->assertSame( 'shift8_woodpecker_status_failed', $result->get_error_code() );
	}

	public function testResponseWithoutPipelineNumberIsInvalid(): void {
		Env::queue( 200, array( 'status' => 'pending' ) );

		$result = $this->client()->triggerPipeline( 'x' );

		$this->assertSame( 'shift8_woodpecker_invalid_response', $result->get_error_code() );
	}

	public function testNetworkErrorIsWrappedWithoutLeakingToken(): void {
		Env::$responses[] = new \WP_Error( 'http_request_failed', 'cURL error 28: timeout while calling with ' . self::TOKEN );

		$result = $this->client()->getPipeline( 1 );

		$this->assertSame( 'shift8_woodpecker_http', $result->get_error_code() );
		$this->assertStringContainsString( 'Could not reach Woodpecker', $result->get_error_message() );
		$this->assertStringNotContainsString( self::TOKEN, $result->get_error_message() );
	}

	public function testRemoteErrorMessageIsScrubbedAndBounded(): void {
		Env::queue( 500, array( 'message' => '<script>alert(1)</script>' . self::TOKEN . str_repeat( 'a', 500 ) ) );

		$message = $this->client()->getPipeline( 1 )->get_error_message();

		$this->assertStringNotContainsString( '<script>', $message );
		$this->assertStringNotContainsString( self::TOKEN, $message );
		$this->assertLessThan( 400, strlen( $message ) );
	}

	public function testConnectionTestChecksUserRepoAndWorkflow(): void {
		Env::queue( 200, array( 'login' => 'ci' ) );
		Env::queue( 200, array( 'id' => 123 ) );
		Env::queue( 200, array( array( 'name' => 'wordpress-deploy' ) ) );

		$lines = $this->client()->testConnection();

		$this->assertSame(
			array(
				'Connection successful.',
				'Authenticated with Woodpecker.',
				'Repository 123 accessible.',
				'Workflow "wordpress-deploy" available.',
			),
			$lines
		);
		$this->assertSame( 'https://woodpecker.example.com/api/user', Env::$requests[0]['url'] );
		$this->assertSame( 'https://woodpecker.example.com/api/repos/123', Env::$requests[1]['url'] );
		foreach ( Env::$requests as $request ) {
			$this->assertSame( 'GET', $request['method'], 'A connection test must never start a pipeline.' );
		}
	}

	public function testConnectionTestFailsOnMissingWorkflow(): void {
		Env::queue( 200, array() );
		Env::queue( 200, array() );
		Env::queue( 200, array( array( 'name' => 'another' ) ) );

		$result = $this->client()->testConnection();

		$this->assertSame( 'shift8_woodpecker_workflow_missing', $result->get_error_code() );
	}

	public function testConnectionTestReportsBadToken(): void {
		Env::queue( 401, array() );

		$result = $this->client()->testConnection();

		$this->assertSame( 'shift8_woodpecker_auth', $result->get_error_code() );
	}

	public function testPipelineUrlIsBuiltFromConfiguredBase(): void {
		$this->assertSame( 'https://woodpecker.example.com/repos/123/pipeline/5', $this->client()->pipelineUrl( 5 ) );
	}
}
