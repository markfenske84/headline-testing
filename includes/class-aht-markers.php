<?php
/**
 * Theme headline marker helpers.
 *
 * @package AndreianHeadlineTesting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AHT_Markers {

	/**
	 * Data attributes for a headline element tied to a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string HTML attributes (leading space).
	 */
	public static function attributes( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id < 1 ) {
			return '';
		}

		return sprintf(
			' data-aht-post-id="%1$d" data-aht-headline="1"',
			$post_id
		);
	}
}

if ( ! function_exists( 'aht_headline_attributes' ) ) {
	/**
	 * @param int $post_id Post ID.
	 * @return string
	 */
	function aht_headline_attributes( $post_id ) {
		return AHT_Markers::attributes( $post_id );
	}
}
