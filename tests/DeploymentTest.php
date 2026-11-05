<?php
/**
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Tests;

use Shift8\Woodpecker\Deployment;

class DeploymentTest extends TestCase {

	public function testTriggerStartsExactlyOnePipelineAndPersistsItsNumber(): void {
		Env::queue(
			200,
			array(
				'number' => 184,
				'status' => 'pending',
			)
		);

		$result = $this->deployment()->trigger();

		$this->assertSame( 184, $result['number'] );
		$this->assertSame( 'pending', $result['state'] );
		$this->assertTrue( $result['active'] );
		$this->assertCount( 1, Env::$requests );
		$this->assertSame( 184, Env::$options[ Deployment::STATE_OPTION ]['number'] );
		$this->assertSame( 7, Env::$options[ Deployment::STATE_OPTION ]['user_id'] );
	}

	public function testMessageTemplatePlaceholdersAreExpanded(): void {
		$this->configure( array( 'message' => 'Deploy {site} by {user}' ) );
		Env::queue(
			200,
			array(
				'number' => 1,
				'status' => 'pending',
			)
		);

		$this->deployment()->trigger();

		$body = json_decode( Env::$requests[0]['args']['body'], true );
		$this->assertSame( 'Deploy Example Site by Casey Admin', $body['message'] );
	}

	public function testSecondTriggerWithinLockWindowIsRejectedWithoutARequest(): void {
		Env::queue(
			200,
			array(
				'number' => 184,
				'status' => 'pending',
			)
		);
		$deployment = $this->deployment();
		$deployment->trigger();

		$second = $deployment->trigger();

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'shift8_woodpecker_locked', $second->get_error_code() );
		$this->assertCount( 1, Env::$requests );
	}

	public function testTriggerIsBlockedWhileTrackedPipelineIsActive(): void {
		Env::$options[ Deployment::STATE_OPTION ] = array(
			'number' => 50,
			'status' => 'running',
		);
		Env::queue(
			200,
			array(
				'number' => 50,
				'status' => 'running',
			)
		);

		$result = $this->deployment()->trigger();

		$this->assertSame( 'shift8_woodpecker_active', $result->get_error_code() );
		$this->assertStringContainsString( '#50', $result->get_error_message() );
		$this->assertCount( 1, Env::$requests );
		$this->assertSame( 'GET', Env::$requests[0]['method'] );
	}

	public function testTriggerProceedsWhenPreviousPipelineFinished(): void {
		Env::$options[ Deployment::STATE_OPTION ] = array(
			'number' => 50,
			'status' => 'running',
		);
		Env::queue(
			200,
			array(
				'number' => 50,
				'status' => 'success',
			)
		);
		Env::queue(
			200,
			array(
				'number' => 51,
				'status' => 'pending',
			)
		);

		$result = $this->deployment()->trigger();

		$this->assertSame( 51, $result['number'] );
		$this->assertSame( 51, Env::$options[ Deployment::STATE_OPTION ]['number'] );
	}

	public function testFailedTriggerReleasesLockAndStoresNothing(): void {
		Env::queue( 500, array( 'message' => 'boom' ) );

		$result = $this->deployment()->trigger();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertArrayNotHasKey( Deployment::STATE_OPTION, Env::$options );
		$this->assertArrayNotHasKey( Deployment::LOCK_KEY, Env::$transients );
	}

	public function testInvalidConfigurationBlocksTrigger(): void {
		$this->configure( array( 'repo_id' => '' ) );

		$result = $this->deployment()->trigger();

		$this->assertSame( 'shift8_woodpecker_config', $result->get_error_code() );
		$this->assertSame( array(), Env::$requests );
	}

	public function testRefreshQueriesStoredPipelineNumberAndRecordsSuccess(): void {
		Env::$options[ Deployment::STATE_OPTION ] = array(
			'number' => 77,
			'status' => 'running',
		);
		Env::queue(
			200,
			array(
				'number'   => 77,
				'status'   => 'success',
				'started'  => 10,
				'finished' => 90,
			)
		);

		$result = $this->deployment()->refresh();

		$this->assertStringEndsWith( '/pipelines/77', Env::$requests[0]['url'] );
		$this->assertSame( 'success', $result['state'] );
		$this->assertFalse( $result['active'] );
		$this->assertSame( 'success', Env::$options[ Deployment::STATE_OPTION ]['status'] );
		$this->assertSame( 90, Env::$options[ Deployment::STATE_OPTION ]['finished'] );
	}

	public function testRefreshRepresentsFailure(): void {
		Env::$options[ Deployment::STATE_OPTION ] = array(
			'number' => 77,
			'status' => 'running',
		);
		Env::queue(
			200,
			array(
				'number' => 77,
				'status' => 'failure',
			)
		);

		$this->assertSame( 'failure', $this->deployment()->refresh()['state'] );
	}

	public function testRefreshDoesNotCallApiForFinishedPipeline(): void {
		Env::$options[ Deployment::STATE_OPTION ] = array(
			'number' => 77,
			'status' => 'success',
		);

		$result = $this->deployment()->refresh();

		$this->assertSame( 'success', $result['state'] );
		$this->assertSame( array(), Env::$requests );
	}

	public function testRefreshWithNothingTrackedReturnsNull(): void {
		$this->assertNull( $this->deployment()->refresh() );
	}

	public function testUnknownStatusDegradesGracefully(): void {
		Env::$options[ Deployment::STATE_OPTION ] = array(
			'number' => 77,
			'status' => 'running',
		);
		Env::queue(
			200,
			array(
				'number' => 77,
				'status' => 'brand_new_status',
			)
		);

		$result = $this->deployment()->refresh();

		$this->assertSame( 'unknown', $result['state'] );
		$this->assertFalse( $result['active'] );
	}

	public function testDescribeContainsNoSecrets(): void {
		Env::$options[ Deployment::STATE_OPTION ] = array(
			'number'   => 5,
			'status'   => 'success',
			'started'  => 60,
			'finished' => 120,
		);

		$encoded = json_encode( $this->deployment()->describe( $this->deployment()->last() ) );

		$this->assertStringNotContainsString( self::TOKEN, $encoded );
		$this->assertArrayNotHasKey( 'url', $this->deployment()->describe( $this->deployment()->last() ) );
	}
}
