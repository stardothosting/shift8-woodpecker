<?php
/**
 * The single "Woodpecker" page under the shared Shift8 menu, with tabs
 * (same layout as the other Shift8 plugins): Deployment first, then Settings.
 *
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Admin;

use Shift8\Woodpecker\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminPage {

	public const SLUG        = 'shift8-woodpecker';
	public const PARENT_SLUG = 'shift8-settings';

	public const TAB_DEPLOYMENT = 'deployment';
	public const TAB_SETTINGS   = 'settings';

	private DeploymentPage $deployment;
	private SettingsPage $settings;

	public function __construct( DeploymentPage $deployment, SettingsPage $settings ) {
		$this->deployment = $deployment;
		$this->settings   = $settings;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public static function tabUrl( string $tab ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab );
	}

	/**
	 * Whichever Shift8 plugin loads first creates the shared top-level menu.
	 */
	public function addMenu(): void {
		if ( empty( $GLOBALS['admin_page_hooks'][ self::PARENT_SLUG ] ) ) {
			add_menu_page(
				__( 'Shift8 Settings', 'shift8-deployment-manager-for-woodpecker-ci' ),
				'Shift8',
				Plugin::deployCapability(),
				self::PARENT_SLUG,
				array( $this, 'renderWelcome' ),
				'dashicons-shift8'
			);
		}
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Woodpecker', 'shift8-deployment-manager-for-woodpecker-ci' ),
			__( 'Woodpecker', 'shift8-deployment-manager-for-woodpecker-ci' ),
			Plugin::deployCapability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The shared Shift8 menu icon is needed on every admin screen, so it is
	 * attached to an empty style handle instead of echoing a <style> tag.
	 */
	public function menuIconStyle(): void {
		wp_register_style( 'shift8-woodpecker-menu', false, array(), SHIFT8_WOODPECKER_PLUGIN_VERSION );
		wp_enqueue_style( 'shift8-woodpecker-menu' );
		wp_add_inline_style( 'shift8-woodpecker-menu', '.dashicons-shift8{background:url("' . esc_url( SHIFT8_WOODPECKER_PLUGIN_URL . 'img/shift8pluginicon.png' ) . '") center no-repeat;}' );
	}

	public function renderWelcome(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Shift8 Plugins', 'shift8-deployment-manager-for-woodpecker-ci' ); ?></h1>
			<p><?php esc_html_e( 'Shift8 is a Toronto based web development and design company specializing in WordPress.', 'shift8-deployment-manager-for-woodpecker-ci' ); ?> <a href="https://www.shift8web.ca" target="_blank" rel="noopener noreferrer">www.shift8web.ca</a></p>
		</div>
		<?php
	}

	/**
	 * Tabs the current user may see. Settings hold credentials-adjacent
	 * configuration and stay restricted to manage_options.
	 *
	 * @return array<string,string> Tab slug => label.
	 */
	private function tabs(): array {
		$tabs = array( self::TAB_DEPLOYMENT => __( 'Deployment', 'shift8-deployment-manager-for-woodpecker-ci' ) );
		if ( current_user_can( Plugin::settingsCapability() ) ) {
			$tabs[ self::TAB_SETTINGS ] = __( 'Settings', 'shift8-deployment-manager-for-woodpecker-ci' );
		}
		return $tabs;
	}

	private function activeTab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selecting a tab is not a state change.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : self::TAB_DEPLOYMENT;
		return isset( $this->tabs()[ $requested ] ) ? $requested : self::TAB_DEPLOYMENT;
	}

	/**
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( string $hook ): void {
		$this->menuIconStyle();

		if ( 'shift8_page_' . self::SLUG !== $hook ) {
			return;
		}
		$this->deployment->enqueue( self::TAB_DEPLOYMENT === $this->activeTab() );
	}

	public function render(): void {
		if ( ! current_user_can( Plugin::deployCapability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'shift8-deployment-manager-for-woodpecker-ci' ), '', array( 'response' => 403 ) );
		}
		$active = $this->activeTab();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Shift8 Deployment Manager', 'shift8-deployment-manager-for-woodpecker-ci' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $this->tabs() as $slug => $label ) : ?>
					<a href="<?php echo esc_url( self::tabUrl( $slug ) ); ?>" class="nav-tab <?php echo $slug === $active ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>
			<?php // This page is outside Settings, so "Settings saved." and validation errors must be printed explicitly. ?>
			<?php settings_errors(); ?>
			<?php
			if ( self::TAB_SETTINGS === $active ) {
				$this->settings->render();
			} else {
				$this->deployment->render();
			}
			?>
		</div>
		<?php
	}
}
