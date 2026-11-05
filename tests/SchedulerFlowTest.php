<?php
/**
 * End-to-end checks of the scheduling path: what an administrator types is
 * what WP-Cron is given, in the site timezone.
 *
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Tests;

use Shift8\Woodpecker\Admin\Ajax;
use Shift8\Woodpecker\Api\Client;
use Shift8\Woodpecker\Config;
use Shift8\Woodpecker\Plugin;
use Shift8\Woodpecker\Scheduler;
use Shift8\Woodpecker\Support\ActivityLog;

class SchedulerFlowTest extends TestCase {

	private function ajax(): Ajax {
		return new Ajax( $this->deployment(), new Client( new Config() ), $this->scheduler(), $this->activity() );
	}

	/**
	 * A future local date/time as the datetime-local input would submit it.
	 */
	private function futureInput( string $tz, int $days = 3 ): string {
		return ( new \DateTimeImmutable( 'now', new \DateTimeZone( $tz ) ) )->modify( '+' . $days . ' days' )->setTime( 3, 0 )->format( 'Y-m-d\TH:i' );
	}

	public function testEnteredLocalTimeBecomesTheCronTimestampAndIsEchoedBack(): void {
		Env::$timezone = 'America/Toronto';
		$input         = $this->futureInput( 'America/Toronto' );
		$_POST         = array( 'run_at' => $input );

		$response = $this->json( array( $this->ajax(), 'schedule' ) );
		$_POST    = array();

		$this->assertTrue( $response->success );
		$this->assertCount( 1, Env::$cron, 'Exactly one WP-Cron event is created.' );
		list( $timestamp, $hook, $args ) = Env::$cron[0];

		$this->assertSame( Scheduler::HOOK, $hook );
		$this->assertSame( array( $timestamp ), $args );

		// The cron time, viewed in the site timezone, is exactly what was typed.
		$this->assertSame( str_replace( 'T', ' ', $input ), wp_date( 'Y-m-d H:i', $timestamp ) );
		// ...and it is a real instant: 03:00 Toronto is 07:00 or 08:00 UTC depending on DST.
		$offset = ( new \DateTimeZone( 'America/Toronto' ) )->getOffset( new \DateTimeImmutable( '@' . $timestamp ) );
		$this->assertContains( $offset, array( -18000, -14400 ) );
		$this->assertSame( sprintf( '%02d:00', 3 - $offset / 3600 ), gmdate( 'H:i', $timestamp ) );

		// Stored state, browser response and audit log all agree.
		$this->assertSame( $timestamp, Env::$options[ Scheduler::OPTION ]['timestamp'] );
		$this->assertSame( 7, Env::$options[ Scheduler::OPTION ]['user_id'] );
		$this->assertSame( str_replace( 'T', ' ', $input ), $response->data['schedule']['when'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $timestamp ), Env::$logRows[0]['scheduled_for'] );
		$this->assertStringContainsString( 'for ' . str_replace( 'T', ' ', $input ), $response->data['log'][0]['details'] );
	}

	/**
	 * @dataProvider localTimes
	 */
	public function testLocalTimeConversionAcrossDaylightSaving( string $tz, string $input, string $expected_utc ): void {
		Env::$timezone = $tz;

		$timestamp = $this->scheduler()->parse( $input );

		$this->assertSame( $expected_utc, gmdate( 'Y-m-d H:i', $timestamp ) );
	}

	/**
	 * @return array<string,array{string,string,string}>
	 */
	public function localTimes(): array {
		return array(
			'Toronto summer (EDT, UTC-4)' => array( 'America/Toronto', '2026-07-01T09:30', '2026-07-01 13:30' ),
			'Toronto winter (EST, UTC-5)' => array( 'America/Toronto', '2026-12-01T09:30', '2026-12-01 14:30' ),
			'UTC site'                    => array( 'UTC', '2026-12-01T09:30', '2026-12-01 09:30' ),
			'Tokyo (no DST, UTC+9)'       => array( 'Asia/Tokyo', '2026-12-01T09:30', '2026-12-01 00:30' ),
		);
	}

	public function testCronEventFiresTheManualPipelineAsTheSchedulingUser(): void {
		Env::$timezone = 'America/Toronto';
		$scheduler     = $this->scheduler();
		$scheduler->register();
		$input = $this->futureInput( 'America/Toronto', 1 );
		$_POST = array( 'run_at' => $input );
		$this->json( array( new Ajax( $this->deployment(), new Client( new Config() ), $scheduler, $this->activity() ), 'schedule' ) );
		$_POST     = array();
		$timestamp = Env::$cron[0][0];
		Env::queue(
			200,
			array(
				'number' => 321,
				'status' => 'pending',
			)
		);
		$this->assertSame( array(), Env::$requests, 'Nothing is sent to Woodpecker until the cron event fires.' );

		// WP-Cron firing the event.
		do_action( Scheduler::HOOK, ...Env::$cron[0][2] );

		$this->assertCount( 1, Env::$requests );
		$this->assertSame( 'https://woodpecker.example.com/api/repos/123/pipelines', Env::$requests[0]['url'] );
		$this->assertNull( $scheduler->current() );
		$this->assertSame( array( ActivityLog::SCHEDULED, ActivityLog::SCHEDULED_RUN ), array_column( Env::$logRows, 'event' ) );
		$this->assertSame( 321, Env::$logRows[1]['pipeline_number'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $timestamp ), Env::$logRows[0]['scheduled_for'] );
	}

	public function testRegisterAttachesRunToTheCronHook(): void {
		$this->scheduler()->register();

		$this->assertArrayHasKey( Scheduler::HOOK, Env::$actions );
	}

	public function testUnscheduleAjaxRemovesTheCronEvent(): void {
		$scheduler = $this->scheduler();
		$scheduler->schedule( 7, time() + 3600 );

		$response = ( new Ajax( $this->deployment(), new Client( new Config() ), $scheduler, $this->activity() ) );
		$result   = $this->json( array( $response, 'unschedule' ) );

		$this->assertTrue( $result->success );
		$this->assertSame( array(), Env::$cron );
		$this->assertNull( $result->data['schedule'] );
		$this->assertSame( 'Cancelled scheduled deployment', $result->data['log'][0]['action'] );
	}

	public function testDeactivationClearsPendingScheduleAndCronEvent(): void {
		$this->scheduler()->schedule( 7, time() + 3600 );

		Scheduler::clearAll();

		$this->assertSame( array(), Env::$cron );
		$this->assertArrayNotHasKey( Scheduler::OPTION, Env::$options );
	}

	public function testCronHookIsRegisteredOutsideTheAdminButMenusAreNot(): void {
		global $wpdb;
		$wpdb = new \wpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- test double.

		Env::$isAdmin = false;
		( new Plugin() )->register();
		$this->assertArrayHasKey( Scheduler::HOOK, Env::$actions, 'WP-Cron requests are not admin requests.' );
		$this->assertArrayNotHasKey( 'admin_menu', Env::$actions );
		$this->assertArrayNotHasKey( 'wp_ajax_shift8_woodpecker_deploy', Env::$actions );

		Env::$actions = array();
		Env::$isAdmin = true;
		( new Plugin() )->register();
		$this->assertArrayHasKey( Scheduler::HOOK, Env::$actions );
		$this->assertArrayHasKey( 'admin_menu', Env::$actions );
		$this->assertArrayHasKey( 'wp_ajax_shift8_woodpecker_schedule', Env::$actions );
		$this->assertArrayNotHasKey( 'wp_ajax_shift8_woodpecker_cancel', Env::$actions, 'End users cannot cancel deployments.' );
	}
}
