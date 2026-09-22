<?php
/**
 * Plugin Name: Headline Testing
 * Description: A/B headline tests with engagement tracking, reports, and automatic winners.
 * Version: 1.0.0
 * Author: Mark Fenske
 * Update URI: https://github.com/markfenske84/headline-testing
 * Text Domain: headline-testing
 *
 * @package HeadlineTesting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AHT_VERSION', '1.0.0' );
define( 'AHT_PLUGIN_FILE', __FILE__ );
define( 'AHT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AHT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( AHT_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once AHT_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$aht_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/markfenske84/headline-testing/',
	__FILE__,
	'headline-testing'
);
$aht_update_checker->setBranch( 'main' );
$aht_update_checker->getVcsApi()->enableReleaseAssets();

add_action(
	'admin_init',
	static function () {
		if ( ! isset( $_GET['aht_check_updates'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $aht_update_checker;
		if ( isset( $aht_update_checker ) ) {
			$aht_update_checker->checkForUpdates();
		}
		wp_safe_redirect( admin_url( 'plugins.php?aht_update_checked=1' ) );
		exit;
	}
);

add_action(
	'admin_notices',
	static function () {
		if ( ! isset( $_GET['aht_update_checked'] ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Headline Testing update check completed. Review the Plugins screen for available updates.', 'headline-testing' ) . '</p></div>';
	}
);

require_once AHT_PLUGIN_DIR . 'includes/class-aht-install.php';
require_once AHT_PLUGIN_DIR . 'includes/class-aht-test-repository.php';
require_once AHT_PLUGIN_DIR . 'includes/class-aht-statistics.php';
require_once AHT_PLUGIN_DIR . 'includes/class-aht-winner-evaluator.php';
require_once AHT_PLUGIN_DIR . 'includes/class-aht-rest.php';
require_once AHT_PLUGIN_DIR . 'includes/class-aht-frontend.php';
require_once AHT_PLUGIN_DIR . 'includes/class-aht-markers.php';
require_once AHT_PLUGIN_DIR . 'admin/class-aht-admin.php';
require_once AHT_PLUGIN_DIR . 'admin/class-aht-csv-export.php';

/**
 * Main plugin bootstrap.
 */
final class Andreian_Headline_Testing {

	/**
	 * @var Andreian_Headline_Testing|null
	 */
	private static $instance = null;

	/**
	 * @return Andreian_Headline_Testing
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( AHT_PLUGIN_FILE, array( 'AHT_Install', 'activate' ) );
		register_deactivation_hook(
			AHT_PLUGIN_FILE,
			static function () {
				wp_clear_scheduled_hook( AHT_Winner_Evaluator::CRON_HOOK );
			}
		);
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Load components.
	 */
	public function init() {
		AHT_Install::maybe_upgrade();
		AHT_REST::register();
		AHT_Frontend::register();
		AHT_Winner_Evaluator::register();

		if ( is_admin() ) {
			AHT_Admin::register();
			AHT_CSV_Export::register();
		}
	}
}

Andreian_Headline_Testing::instance();
