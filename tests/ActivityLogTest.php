<?php
/**
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Tests;

use Shift8\Woodpecker\Support\ActivityLog;

class ActivityLogTest extends TestCase {

	public function testImmediateDeployRecordsUserTimeAndPipeline(): void {
		Env::queue(
			200,
			array(
				'number' => 184,
				'status' => 'pending',
			)
		);
		$before = time();

		$this->deployment()->trigger();

		$row = Env::$logRows[0];
		$this->assertSame( ActivityLog::DEPLOY, $row['event'] );
		$this->assertSame( 7, $row['user_id'] );
		$this->assertSame( 'casey', $row['user_login'] );
		$this->assertSame( 184, $row['pipeline_number'] );
		$this->assertGreaterThanOrEqual( $before, strtotime( $row['created_at'] . ' UTC' ) );
		$this->assertLessThanOrEqual( time(), strtotime( $row['created_at'] . ' UTC' ) );
	}

	public function testFailedTriggerIsLoggedWithoutSecrets(): void {
		Env::queue( 500, array( 'message' => 'boom ' . self::TOKEN ) );

		$this->deployment()->trigger();

		$row = Env::$logRows[0];
		$this->assertSame( ActivityLog::DEPLOY_FAILED, $row['event'] );
		$this->assertStringNotContainsString( self::TOKEN, $row['details'] );
	}

	public function testDoubleClickRejectionIsNotLogged(): void {
		Env::queue(
			200,
			array(
				'number' => 1,
				'status' => 'pending',
			)
		);
		$deployment = $this->deployment();
		$deployment->trigger();

		$deployment->trigger();

		$this->assertCount( 1, Env::$logRows );
	}

	public function testCancelIsLogged(): void {
		Env::$options['shift8_woodpecker_last_pipeline'] = array(
			'number' => 5,
			'status' => 'running',
		);
		Env::queue( 200, array() );
		Env::queue(
			200,
			array(
				'number' => 5,
				'status' => 'killed',
			)
		);

		$this->deployment()->cancel();

		$this->assertSame( ActivityLog::CANCELLED, Env::$logRows[0]['event'] );
		$this->assertSame( 5, Env::$logRows[0]['pipeline_number'] );
	}

	public function testRecentReturnsNewestFirstWithLabelsAndLimit(): void {
		$log = $this->activity();
		$log->record( 7, ActivityLog::DEPLOY, array( 'pipeline' => 1 ) );
		$log->record( 9, ActivityLog::SCHEDULED, array( 'scheduled_for' => 1800000000 ) );
		$log->record( 0, ActivityLog::SCHEDULED_MISSED );

		$rows = $log->recent( 2 );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'system', $rows[0]['user'] );
		$this->assertSame( 'Scheduled deployment missed', $rows[0]['action'] );
		$this->assertSame( 'user9', $rows[1]['user'] );
		$this->assertStringContainsString( 'for ', $rows[1]['details'] );
	}

	public function testDetailsAreSanitizedAndBounded(): void {
		$this->activity()->record( 7, ActivityLog::DEPLOY_FAILED, array( 'details' => '<b>' . str_repeat( 'x', 400 ) . '</b>' ) );

		$this->assertSame( 255, strlen( Env::$logRows[0]['details'] ) );
		$this->assertStringNotContainsString( '<b>', Env::$logRows[0]['details'] );
	}
}
