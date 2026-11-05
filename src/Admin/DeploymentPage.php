<?php
/**
 * Deployment tab (push, schedule, test, log) and its admin assets.
 *
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Admin;

use Shift8\Woodpecker\Config;
use Shift8\Woodpecker\Deployment;
use Shift8\Woodpecker\Plugin;
use Shift8\Woodpecker\Scheduler;
use Shift8\Woodpecker\Support\ActivityLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DeploymentPage {

	private Config $config;
	private Deployment $deployment;
	private Scheduler $scheduler;
	private ActivityLog $activity;

	public function __construct( Config $config, Deployment $deployment, Scheduler $scheduler, ActivityLog $activity ) {
		$this->config     = $config;
		$this->deployment = $deployment;
		$this->scheduler  = $scheduler;
		$this->activity   = $activity;
	}

	/**
	 * @param bool $is_deploy Whether the deployment tab is the one being shown.
	 */
	public function enqueue( bool $is_deploy ): void {
		wp_enqueue_style( 'shift8-woodpecker', SHIFT8_WOODPECKER_PLUGIN_URL . 'css/admin.css', array(), SHIFT8_WOODPECKER_PLUGIN_VERSION );
		wp_enqueue_script( 'shift8-woodpecker', SHIFT8_WOODPECKER_PLUGIN_URL . 'js/admin.js', array(), SHIFT8_WOODPECKER_PLUGIN_VERSION, true );

		// Deliberately limited to non-secret values: no token, URL, repo ID, branch or workflow.
		// Refreshing here records the finish of a deployment that ended while no one had the page open.
		$current = $is_deploy ? $this->deployment->refresh() : null;
		wp_localize_script(
			'shift8-woodpecker',
			'shift8Woodpecker',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( Ajax::NONCE_ACTION ),
				'interval' => $this->config->pollInterval() * 1000,
				'pipeline' => is_array( $current ) ? $current : null,
				'schedule' => $is_deploy ? $this->scheduler->describe( $this->scheduler->current() ) : null,
				'log'      => $is_deploy ? $this->activity->recent( 50 ) : array(),
				'i18n'     => array(
					'confirmDeploy'     => __( "Deploy staging WordPress content to production?\n\nThis will start the configured Woodpecker production deployment.", 'shift8-woodpecker-ci' ),
					/* translators: %s: date and time. */
					'confirmSchedule'   => __( "Schedule the production deployment for %s?\n\nIt will start automatically at that time.", 'shift8-woodpecker-ci' ),
					'confirmUnschedule' => __( 'Cancel the scheduled deployment?', 'shift8-woodpecker-ci' ),
					/* translators: 1: date and time, 2: user login. */
					'scheduledFor'      => __( 'Deployment scheduled for %1$s by %2$s.', 'shift8-woodpecker-ci' ),
					'overdue'           => __( 'This schedule is overdue. WP-Cron has not run it yet.', 'shift8-woodpecker-ci' ),
					'cancelSchedule'    => __( 'Cancel Schedule', 'shift8-woodpecker-ci' ),
					'chooseTime'        => __( 'Choose a date and time first.', 'shift8-woodpecker-ci' ),
					'noActivity'        => __( 'No activity yet.', 'shift8-woodpecker-ci' ),
					'started'           => __( 'Deployment started.', 'shift8-woodpecker-ci' ),
					'inProgress'        => __( 'A deployment is in progress. Its result will appear in the activity log.', 'shift8-woodpecker-ci' ),
					'requestFailed'     => __( 'The request failed. Check your connection and try again.', 'shift8-woodpecker-ci' ),
					'testing'           => __( 'Testing…', 'shift8-woodpecker-ci' ),
				),
			)
		);
	}

	public function render(): void {
		if ( ! current_user_can( Plugin::deployCapability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'shift8-woodpecker-ci' ), '', array( 'response' => 403 ) );
		}
		$valid = $this->config->validate();
		?>
		<div class="s8w-deployment">
			<h2><?php esc_html_e( 'Production Deployment', 'shift8-woodpecker-ci' ); ?></h2>
			<?php if ( is_wp_error( $valid ) ) : ?>
				<div class="notice notice-error inline"><p>
					<?php echo esc_html( $valid->get_error_message() ); ?>
					<a href="<?php echo esc_url( AdminPage::tabUrl( AdminPage::TAB_SETTINGS ) ); ?>"><?php esc_html_e( 'Open settings', 'shift8-woodpecker-ci' ); ?></a>
				</p></div>
			<?php else : ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Woodpecker', 'shift8-woodpecker-ci' ); ?></th><td><?php echo esc_html( $this->config->baseUrl() ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Repository', 'shift8-woodpecker-ci' ); ?></th><td><?php echo esc_html( (string) $this->config->repoId() ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Branch', 'shift8-woodpecker-ci' ); ?></th><td><?php echo esc_html( $this->config->branch() ); ?></td></tr>
					<?php if ( '' !== $this->config->workflow() ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Workflow', 'shift8-woodpecker-ci' ); ?></th><td><?php echo esc_html( $this->config->workflow() ); ?></td></tr>
					<?php endif; ?>
				</table>
				<p>
					<button type="button" class="button button-primary button-hero" id="s8w-deploy"><?php esc_html_e( 'Deploy to Production', 'shift8-woodpecker-ci' ); ?></button>
				</p>
				<div id="s8w-message" role="status" aria-live="polite"></div>

				<h2><?php esc_html_e( 'Schedule a deployment', 'shift8-woodpecker-ci' ); ?></h2>
				<p>
					<label for="s8w-run-at" class="screen-reader-text"><?php esc_html_e( 'Date and time', 'shift8-woodpecker-ci' ); ?></label>
					<input type="datetime-local" id="s8w-run-at" min="<?php echo esc_attr( (string) wp_date( 'Y-m-d\TH:i', time() + Scheduler::MIN_LEAD ) ); ?>" />
					<button type="button" class="button" id="s8w-schedule"><?php esc_html_e( 'Schedule Deployment', 'shift8-woodpecker-ci' ); ?></button>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: site timezone name. */
						esc_html__( 'Times use the site timezone (%s). Scheduled deployments are started by WP-Cron; a system cron calling wp-cron.php every minute is recommended.', 'shift8-woodpecker-ci' ),
						esc_html( wp_timezone_string() )
					);
					?>
				</p>
				<div id="s8w-schedule-status"></div>

				<?php if ( current_user_can( Plugin::settingsCapability() ) ) : ?>
					<h2><?php esc_html_e( 'Test connection', 'shift8-woodpecker-ci' ); ?></h2>
					<p><?php esc_html_e( 'Checks the saved settings. Does not start a deployment.', 'shift8-woodpecker-ci' ); ?></p>
					<p><button type="button" class="button" id="s8w-test"><?php esc_html_e( 'Test Woodpecker Connection', 'shift8-woodpecker-ci' ); ?></button></p>
					<div id="s8w-test-result" aria-live="polite"></div>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Activity log', 'shift8-woodpecker-ci' ); ?></h2>
				<table class="widefat striped" id="s8w-log">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Date', 'shift8-woodpecker-ci' ); ?></th>
						<th scope="col"><?php esc_html_e( 'User', 'shift8-woodpecker-ci' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Action', 'shift8-woodpecker-ci' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Details', 'shift8-woodpecker-ci' ); ?></th>
					</tr></thead>
					<tbody></tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
