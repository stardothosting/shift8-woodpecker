<?php
/**
 * Settings tab.
 *
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Admin;

use Shift8\Woodpecker\Config;
use Shift8\Woodpecker\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsPage {

	public const GROUP = 'shift8_woodpecker_group';

	private Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'registerSetting' ) );
	}

	public function registerSetting(): void {
		register_setting(
			self::GROUP,
			Config::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Validate submitted settings. Invalid values are rejected with a notice
	 * and the previously stored value is kept; constant-managed fields are
	 * never taken from the form.
	 *
	 * @param mixed $input Raw form input.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? wp_unslash( $input ) : array();
		$old   = get_option( Config::OPTION, array() );
		$old   = is_array( $old ) ? $old : array();
		$clean = array();

		$url_in = isset( $input['url'] ) ? (string) $input['url'] : '';
		$url    = Config::normalizeUrl( $url_in );
		if ( '' === $url && '' !== trim( $url_in ) ) {
			$this->error( 'shift8_woodpecker_url', __( 'The Woodpecker URL must be a valid http(s) URL without credentials. The previous value was kept.', 'shift8-woodpecker' ) );
			$url = isset( $old['url'] ) ? (string) $old['url'] : '';
		}
		$clean['url'] = $url;

		$repo_in = isset( $input['repo_id'] ) ? trim( (string) $input['repo_id'] ) : '';
		if ( '' === $repo_in || ( ctype_digit( $repo_in ) && (int) $repo_in > 0 ) ) {
			$clean['repo_id'] = '' === $repo_in ? '' : (int) $repo_in;
		} else {
			$this->error( 'shift8_woodpecker_repo', __( 'The repository ID must be a positive whole number. The previous value was kept.', 'shift8-woodpecker' ) );
			$clean['repo_id'] = isset( $old['repo_id'] ) ? $old['repo_id'] : '';
		}

		foreach ( array( 'branch', 'workflow' ) as $key ) {
			$value = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			if ( '' !== $value && '' === Config::cleanRef( $value ) ) {
				/* translators: %s: setting name. */
				$this->error( 'shift8_woodpecker_' . $key, sprintf( __( 'The %s may only contain letters, numbers, dots, dashes, underscores and slashes. The previous value was kept.', 'shift8-woodpecker' ), $key ) );
				$value = isset( $old[ $key ] ) ? (string) $old[ $key ] : '';
			}
			$clean[ $key ] = $value;
		}

		// The form can never change a constant-managed value; the stored one is kept as-is.
		foreach ( array( 'url', 'repo_id', 'branch', 'workflow' ) as $key ) {
			if ( $this->config->isConstant( $key ) ) {
				$clean[ $key ] = isset( $old[ $key ] ) ? $old[ $key ] : '';
			}
		}

		$message          = isset( $input['message'] ) ? sanitize_text_field( (string) $input['message'] ) : '';
		$clean['message'] = mb_substr( $message, 0, 200 );

		$interval               = isset( $input['poll_interval'] ) ? (int) $input['poll_interval'] : Config::DEFAULT_POLL_INTERVAL;
		$clean['poll_interval'] = max( Config::MIN_POLL_INTERVAL, min( Config::MAX_POLL_INTERVAL, $interval ) );

		return $clean;
	}

	public function render(): void {
		if ( ! current_user_can( Plugin::settingsCapability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'shift8-woodpecker' ), '', array( 'response' => 403 ) );
		}
		$stored = get_option( Config::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		?>
		<div class="s8w-settings">
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<?php
					$this->textRow( 'url', __( 'Woodpecker URL', 'shift8-woodpecker' ), $this->config->baseUrl(), 'https://woodpecker.example.com', 'url' );
					$this->textRow( 'repo_id', __( 'Repository ID', 'shift8-woodpecker' ), (string) ( $this->config->repoId() ?: '' ), '123', 'text' );
					$this->textRow( 'branch', __( 'Branch', 'shift8-woodpecker' ), $this->config->branch(), 'production', 'text' );
					$this->textRow( 'workflow', __( 'Workflow (optional)', 'shift8-woodpecker' ), $this->config->workflow(), 'wordpress-deploy', 'text' );
					$this->tokenRow();
					?>
					<tr>
						<th scope="row"><label for="s8w-message"><?php esc_html_e( 'Deployment message', 'shift8-woodpecker' ); ?></label></th>
						<td>
							<input type="text" id="s8w-message" class="regular-text" maxlength="200" name="<?php echo esc_attr( Config::OPTION ); ?>[message]" value="<?php echo esc_attr( isset( $stored['message'] ) ? (string) $stored['message'] : '' ); ?>" placeholder="<?php echo esc_attr( Config::DEFAULT_MESSAGE ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional. Placeholders: {user}, {site}.', 'shift8-woodpecker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="s8w-poll"><?php esc_html_e( 'Status polling interval (seconds)', 'shift8-woodpecker' ); ?></label></th>
						<td>
							<input type="number" id="s8w-poll" class="small-text" min="<?php echo (int) Config::MIN_POLL_INTERVAL; ?>" max="<?php echo (int) Config::MAX_POLL_INTERVAL; ?>" name="<?php echo esc_attr( Config::OPTION ); ?>[poll_interval]" value="<?php echo (int) $this->config->pollInterval(); ?>" />
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private function textRow( string $key, string $label, string $value, string $placeholder, string $type ): void {
		$locked = $this->config->isConstant( $key );
		$id     = 's8w-' . $key;
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>" class="regular-text" name="<?php echo esc_attr( Config::OPTION . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" <?php disabled( $locked ); ?> />
				<?php $this->lockedNote( $locked ); ?>
			</td>
		</tr>
		<?php
	}

	private function tokenRow(): void {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'API token', 'shift8-woodpecker' ); ?></th>
			<td>
				<?php if ( $this->config->hasToken() ) : ?>
					<p><?php esc_html_e( 'Token is configured in wp-config.php.', 'shift8-woodpecker' ); ?></p>
				<?php else : ?>
					<p><strong><?php esc_html_e( 'No token configured.', 'shift8-woodpecker' ); ?></strong> <?php esc_html_e( 'The token is never stored in the database. Add this line to wp-config.php:', 'shift8-woodpecker' ); ?></p>
					<p><code>define( 'SHIFT8_WOODPECKER_TOKEN', 'your-woodpecker-token' );</code></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private function lockedNote( bool $locked ): void {
		if ( $locked ) {
			echo '<p class="description">' . esc_html__( 'Managed by wp-config.php.', 'shift8-woodpecker' ) . '</p>';
		}
	}

	private function error( string $code, string $message ): void {
		add_settings_error( Config::OPTION, $code, $message );
	}
}
