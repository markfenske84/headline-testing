<?php
/**
 * Database installation and upgrades.
 *
 * @package AndreianHeadlineTesting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AHT_Install {

	const DB_VERSION = '1.0.0';

	/**
	 * Plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		update_option( 'aht_db_version', self::DB_VERSION );
		AHT_Winner_Evaluator::schedule();
	}

	/**
	 * Run dbDelta when version changes.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'aht_db_version', '' );
		if ( self::DB_VERSION === $installed ) {
			return;
		}

		self::create_tables();
		update_option( 'aht_db_version', self::DB_VERSION );
	}

	/**
	 * Create custom tables.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$tests   = $wpdb->prefix . 'aht_tests';
		$variants = $wpdb->prefix . 'aht_variants';
		$events  = $wpdb->prefix . 'aht_events';

		$sql_tests = "CREATE TABLE {$tests} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			control_title text NOT NULL,
			scroll_threshold decimal(5,2) NOT NULL DEFAULT 30.00,
			time_threshold int(11) unsigned NOT NULL DEFAULT 78,
			min_impressions int(11) unsigned NOT NULL DEFAULT 200,
			confidence_level decimal(5,2) NOT NULL DEFAULT 95.00,
			min_lift decimal(5,2) NOT NULL DEFAULT 5.00,
			winner_variant_id bigint(20) unsigned DEFAULT NULL,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY status (status)
		) {$charset};";

		$sql_variants = "CREATE TABLE {$variants} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			test_id bigint(20) unsigned NOT NULL,
			headline text NOT NULL,
			is_control tinyint(1) NOT NULL DEFAULT 0,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY test_id (test_id)
		) {$charset};";

		$sql_events = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			test_id bigint(20) unsigned NOT NULL,
			variant_id bigint(20) unsigned NOT NULL,
			event_type varchar(20) NOT NULL,
			visitor_hash char(64) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe (test_id, variant_id, event_type, visitor_hash),
			KEY test_variant (test_id, variant_id)
		) {$charset};";

		dbDelta( $sql_tests );
		dbDelta( $sql_variants );
		dbDelta( $sql_events );
	}
}
