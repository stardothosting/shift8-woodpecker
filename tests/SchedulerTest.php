<?php
/**
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Tests;

use Shift8\Woodpecker\Admin\Ajax;
use Shift8\Woodpecker\Api\Client;
use Shift8\Woodpecker\Config;
use Shift8\Woodpecker\Deployment;
use Shift8\Woodpecker\Scheduler;
use Shift8\Woodpecker\Support\ActivityLog;

class SchedulerTest extends TestCase {

	public function testScheduleStoresOneCronEventAndLogsWhoScheduledIt(): void {
		$when = time() + 3600;

		$result = $this->scheduler()->schedule( 7, $when );

		$this->assertSame( $when, $result['timestamp'] );
		$this->assertSame( 'casey', $result['user'] );
		$this->assertSame( array( array( $when, Scheduler::HOOK, array( $when ) ) ), Env::$cron );
		$this->assertSame( array(), Env::$requests, 'Scheduling must not call Woodpecker.' );
		$this->assertSame( ActivityLog::SCHEDULED, Env::$logRows[0]['event'] );
		$this->assertSame( 7, Env::$logRows[0]['user_id'] );
		$this->assertSame( 'casey', Env::$logRows[0]['user_login'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $when ), Env::$logRows[0]['scheduled_for'] );
	}

	/**
	 * @dataProvider badTimes
	 */
	public function testScheduleRejectsInvalidTimes( int $offset, string $code ): void {
		$result = $this->scheduler()->schedule( 7, time() + $offset );

		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( array(), Env::$cron );
	}

	/**
	 * @return array<string,array{int,string}>
	 */
	public function badTimes(): array {
		return array(
			'past'     => array( -100, 'shift8_woodpecker_schedule_past' ),
			'too soon' => array( 10, 'shift8_woodpecker_schedule_past' ),
			'too far'  => array( Scheduler::MAX_LEAD + 3600, 'shift8_woodpecker_schedule_far' ),
		);
	}

	public function testOnlyOneScheduleAtATime(): void {
		$scheduler = $this->scheduler();
		$scheduler->schedule( 7, time() + 3600 );

		$second = $scheduler->schedule( 7, time() + 7200 );

		$this->assertSame( 'shift8_woodpecker_schedule_exists', $second->get_error_code() );
		$this->assertCount( 1, Env::$cron );
	}

	public function testScheduleRequiresValidConfiguration(): void {
		$this->configure( array( 'repo_id' => '' ) );

		$this->assertSame( 'shift8_woodpecker_config', $this->scheduler()->schedule( 7, time() + 3600 )->get_error_code() );
	}

	public function testCancelRemovesEventAndLogsUser(): void {
		$scheduler = $this->scheduler();
		$scheduler->schedule( 7, time() + 3600 );

		$this->assertTrue( $scheduler->cancel( 9 ) );

		$this->assertSame( array(), Env::$cron );
		$this->assertNull( $scheduler->current() );
		$last = end( Env::$logRows );
		$this->assertSame( ActivityLog::SCHEDULE_CANCELLED, $last['event'] );
		$this->assertSame( 'user9', $last['user_login'] );
	}

	public function testCancelWithoutScheduleFails(): void {
		$this->assertSame( 'shift8_woodpecker_nothing_scheduled', $this->scheduler()->cancel( 7 )->get_error_code() );
	}

	public function testDueScheduleTriggersPipelineAsTheSchedulingUser(): void {
		$scheduler = $this->scheduler();
		$when      = time() + 3600;
		$scheduler->schedule( 7, $when );
		Env::queue(
			200,
			array(
				'number' => 60,
				'status' => 'pending',
			)
		);

		$scheduler->run( $when );

		$this->assertCount( 1, Env::$requests );
		$this->assertSame( 'POST', Env::$requests[0]['method'] );
		$this->assertNull( $scheduler->current(), 'A schedule is consumed when it runs.' );
		$last = end( Env::$logRows );
		$this->assertSame( ActivityLog::SCHEDULED_RUN, $last['event'] );
		$this->assertSame( 7, $last['user_id'] );
		$this->assertSame( 60, $last['pipeline_number'] );
		$this->assertSame( 60, Env::$options[ Deployment::STATE_OPTION ]['number'] );
	}

	public function testCancelledOrReplacedEventDoesNothing(): void {
		$scheduler = $this->scheduler();
		$when      = time() + 3600;
		$scheduler->schedule( 7, $when );
		$scheduler->cancel( 7 );

		$scheduler->run( $when );

		$this->assertSame( array(), Env::$requests );
	}

	public function testRunIgnoresMismatchedTimestamp(): void {
		$scheduler = $this->scheduler();
		$scheduler->schedule( 7, time() + 3600 );

		$scheduler->run( 12345 );

		$this->assertSame( array(), Env::$requests );
		$this->assertNotNull( $scheduler->current() );
	}

	public function testUserNoLongerAuthorizedDoesNotDeploy(): void {
		$scheduler = $this->scheduler();
		$when      = time() + 3600;
		$scheduler->schedule( 7, $when );
		Env::$userCan = false;

		$scheduler->run( $when );

		$this->assertSame( array(), Env::$requests );
		$this->assertSame( ActivityLog::SCHEDULED_FAILED, end( Env::$logRows )['event'] );
	}

	public function testOverdueScheduleIsSkippedAndLogged(): void {
		$scheduler                         = $this->scheduler();
		$overdue                           = time() - 7200;
		Env::$options[ Scheduler::OPTION ] = array(
			'timestamp' => $overdue,
			'user_id'   => 7,
		);

		$scheduler->run( $overdue );

		$this->assertSame( array(), Env::$requests );
		$this->assertSame( ActivityLog::SCHEDULED_MISSED, end( Env::$logRows )['event'] );
		$this->assertNull( $scheduler->current() );
	}

	public function testFailureOfScheduledRunIsLogged(): void {
		$scheduler = $this->scheduler();
		$when      = time() + 3600;
		$scheduler->schedule( 7, $when );
		Env::queue( 500, array( 'message' => 'boom' ) );

		$scheduler->run( $when );

		$last = end( Env::$logRows );
		$this->assertSame( ActivityLog::SCHEDULED_FAILED, $last['event'] );
		$this->assertStringContainsString( 'boom', $last['details'] );
		$this->assertStringNotContainsString( self::TOKEN, $last['details'] );
	}

	public function testParseUsesSiteTimezoneAndRejectsGarbage(): void {
		$scheduler = $this->scheduler();

		$this->assertSame( gmmktime( 3, 0, 0, 11, 6, 2026 ), $scheduler->parse( '2026-11-06T03:00' ) );
		foreach ( array( '', 'tomorrow', '2026-11-06 03:00', '2026-13-45T99:99;' ) as $bad ) {
			$this->assertSame( 'shift8_woodpecker_schedule_invalid', $scheduler->parse( $bad )->get_error_code(), $bad );
		}
	}

	public function testAjaxScheduleValidatesAndRecordsCurrentUser(): void {
		$ajax  = new Ajax( $this->deployment(), new Client( new Config() ), $this->scheduler(), $this->activity() );
		$_POST = array( 'run_at' => gmdate( 'Y-m-d\TH:i', time() + 7200 ) );

		$response = $this->json( array( $ajax, 'schedule' ) );
		$_POST    = array();

		$this->assertTrue( $response->success );
		$this->assertSame( 'casey', $response->data['schedule']['user'] );
		$this->assertSame( 'Scheduled deployment', $response->data['log'][0]['action'] );
		$this->assertSame( array(), Env::$requests );
	}

	public function testAjaxScheduleRejectsBadInput(): void {
		$ajax  = new Ajax( $this->deployment(), new Client( new Config() ), $this->scheduler(), $this->activity() );
		$_POST = array( 'run_at' => 'soon' );

		$response = $this->json( array( $ajax, 'schedule' ) );
		$_POST    = array();

		$this->assertFalse( $response->success );
		$this->assertSame( array(), Env::$cron );
	}
}
