<?php
/**
 * Frontend script registration.
 *
 * @package AndreianHeadlineTesting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AHT_Frontend {

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue tracker on public pages.
	 */
	public static function enqueue() {
		if ( is_admin() ) {
			return;
		}

		$path = AHT_PLUGIN_DIR . 'assets/js/frontend.js';
		if ( ! is_readable( $path ) ) {
			return;
		}

		wp_register_script(
			'aht-frontend',
			AHT_PLUGIN_URL . 'assets/js/frontend.js',
			array(),
			(string) filemtime( $path ),
			true
		);

		wp_localize_script(
			'aht-frontend',
			'ahtConfig',
			array(
				'restUrl'   => esc_url_raw( rest_url( AHT_REST::NS ) ),
				'cookieName'=> 'aht_vid',
				'cookieDays'=> 30,
			)
		);

		wp_enqueue_script( 'aht-frontend' );
	}
}
