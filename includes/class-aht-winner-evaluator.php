<?php
/**
 * Scheduled automatic winner evaluation.
 *
 * @package AndreianHeadlineTesting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AHT_Winner_Evaluator {

	const CRON_HOOK = 'aht_evaluate_winners';

	/**
	 * Register cron.
	 */
	public static function register() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'evaluate_all' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * Schedule hourly evaluation.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Ensure cron exists after updates.
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::schedule();
		}
	}

	/**
	 * Evaluate running tests and complete when criteria met.
	 */
	public static function evaluate_all() {
		$tests = AHT_Test_Repository::get_running_tests();
		foreach ( $tests as $test ) {
			$winner_id = AHT_Statistics::pick_auto_winner_variant_id( $test );
			if ( $winner_id ) {
				AHT_Test_Repository::complete_test( (int) $test->id, $winner_id );
			}
		}
	}
}
