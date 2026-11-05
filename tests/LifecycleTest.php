<?php
/**
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Tests;

use Shift8\Woodpecker\Admin\DeploymentPage;
use Shift8\Woodpecker\Config;
use Shift8\Woodpecker\Support\ActivityLog;
use Shift8\Woodpecker\Support\Logger;

class LifecycleTest extends TestCase {

	public function testInstallCreatesTheLogTableAndRecordsTheVersion(): void {
		$this->activity()->install();

		$this->assertStringContainsString( 'CREATE TABLE wp_shift8_woodpecker_log', Env::$sql[0] );
		foreach ( array( 'user_id', 'user_login', 'event', 'pipeline_number', 'scheduled_for', 'created_at' ) as $column ) {
			$this->assertStringContainsString( $column, Env::$sql[0] );
		}
		$this->assertSame( ActivityLog::DB_VERSION, Env::$options[ ActivityLog::DB_VERSION_OPTION ] );
	}

	public function testMaybeInstallOnlyRunsWhenTheVersionDiffers(): void {
		$log = $this->activity();

		$log->maybeInstall();
		$log->maybeInstall();

		$this->assertCount( 1, Env::$sql );
	}

	public function testDropRemovesTableAndVersion(): void {
		$log = $this->activity();
		$log->install();

		$log->drop();

		$this->assertStringContainsString( 'DROP TABLE IF EXISTS wp_shift8_woodpecker_log', end( Env::$sql ) );
		$this->assertArrayNotHasKey( ActivityLog::DB_VERSION_OPTION, Env::$options );
	}

	public function testLoggerWritesOnlyWhenDebugIsEnabled(): void {
		$file = tempnam( sys_get_temp_dir(), 's8w' );
		$old  = ini_set( 'error_log', $file ); // phpcs:ignore WordPress.PHP.IniSet -- redirecting the log inside a test.
		$log  = new Logger();

		$this->assertFalse( $log->enabled(), 'Off unless WP_DEBUG or SHIFT8_WOODPECKER_DEBUG is set.' );
		$log->log( 'Woodpecker pipeline #1 triggered.' );
		$this->assertSame( '', (string) file_get_contents( $file ) );

		ini_set( 'error_log', (string) $old ); // phpcs:ignore WordPress.PHP.IniSet -- redirecting the log inside a test.
		unlink( $file );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testLoggerWritesPipelineEventsWhenDebugConstantIsSet(): void {
		define( 'SHIFT8_WOODPECKER_DEBUG', true );
		$file = tempnam( sys_get_temp_dir(), 's8w' );
		ini_set( 'error_log', $file ); // phpcs:ignore WordPress.PHP.IniSet -- redirecting the log inside a test.

		( new Logger() )->log( 'Woodpecker pipeline #1 triggered.' );

		$this->assertStringContainsString( '[shift8-woodpecker] Woodpecker pipeline #1 triggered.', (string) file_get_contents( $file ) );
		unlink( $file );
	}

	public function testAdminScriptPayloadContainsNoSecretsOrTargets(): void {
		$this->configure(
			array(
				'branch'   => 'branch-xyz',
				'workflow' => 'workflow-xyz',
			)
		);
		$page = new DeploymentPage( new Config(), $this->deployment(), $this->scheduler(), $this->activity() );

		$page->enqueue( true );

		$payload = json_encode( Env::$localized['shift8Woodpecker'] );
		foreach ( array( self::TOKEN, 'woodpecker.example.com', 'workflow-xyz', 'branch-xyz' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, (string) $payload );
		}
		$this->assertSame( 'nonce', Env::$localized['shift8Woodpecker']['nonce'] );
		$this->assertSame( 5000, Env::$localized['shift8Woodpecker']['interval'] );
	}

	public function testLoadingTheDeploymentTabRecordsAFinishThatHappenedWhileNobodyWasWatching(): void {
		Env::$options['shift8_woodpecker_last_pipeline'] = array(
			'number'  => 8,
			'status'  => 'running',
			'user_id' => 7,
		);
		Env::queue(
			200,
			array(
				'number' => 8,
				'status' => 'success',
			)
		);
		$page = new DeploymentPage( new Config(), $this->deployment(), $this->scheduler(), $this->activity() );

		$page->enqueue( true );

		$this->assertSame( ActivityLog::DEPLOY_FINISHED, Env::$logRows[0]['event'] );
		$this->assertSame( 'success', Env::$logRows[0]['details'] );
		$this->assertFalse( Env::$localized['shift8Woodpecker']['pipeline']['active'] );
	}

	public function testSettingsTabDoesNotQueryWoodpeckerOrExposeTheLog(): void {
		$page = new DeploymentPage( new Config(), $this->deployment(), $this->scheduler(), $this->activity() );
		$this->activity()->record( 7, ActivityLog::DEPLOY );

		$page->enqueue( false );

		$this->assertSame( array(), Env::$requests );
		$this->assertSame( array(), Env::$localized['shift8Woodpecker']['log'] );
	}
}
