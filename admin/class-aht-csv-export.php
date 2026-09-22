<?php
/**
 * CSV export for test reports.
 *
 * @package AndreianHeadlineTesting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AHT_CSV_Export {

	const NONCE = 'aht_export_csv';

	/**
	 * Register export handler.
	 */
	public static function register() {
		add_action( 'admin_post_aht_export_csv', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * Stream CSV download.
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Forbidden', 'andreian-headline-testing' ) );
		}

		check_admin_referer( self::NONCE );

		$test_id = isset( $_GET['test_id'] ) ? (int) $_GET['test_id'] : 0;
		$test    = AHT_Test_Repository::get_test( $test_id );
		if ( ! $test ) {
			wp_die( esc_html__( 'Test not found.', 'andreian-headline-testing' ) );
		}

		$rows = AHT_Statistics::build_variant_report( $test );
		$filename = 'headline-test-' . $test_id . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array(
				'variant_id',
				'is_control',
				'headline',
				'impressions',
				'clicks',
				'scroll',
				'time',
				'engaged_visitors',
				'engagement_rate',
				'lift_vs_control_pct',
				'confidence_pct',
			)
		);

		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$row['variant_id'],
					$row['is_control'] ? 1 : 0,
					$row['headline'],
					$row['impressions'],
					$row['clicks'],
					$row['scroll'],
					$row['time'],
					$row['engaged_visitors'],
					round( 100 * $row['conversion_rate'], 4 ),
					null === $row['lift_vs_control'] ? '' : round( $row['lift_vs_control'], 4 ),
					null === $row['confidence'] ? '' : round( $row['confidence'], 4 ),
				)
			);
		}

		fclose( $out );
		exit;
	}
}
