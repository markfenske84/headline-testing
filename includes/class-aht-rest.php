<?php
/**
 * Public REST API for variants and events.
 *
 * @package AndreianHeadlineTesting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AHT_REST {

	const NS = 'andreian-headline-testing/v1';

	/**
	 * Register routes.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Route definitions.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/active',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_active_tests' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'post_ids' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/events',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_events' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_active_tests( WP_REST_Request $request ) {
		$raw      = (string) $request->get_param( 'post_ids' );
		$post_ids = array_filter( array_map( 'intval', explode( ',', $raw ) ) );
		$map      = AHT_Test_Repository::get_running_tests_for_posts( $post_ids );

		$payload = array();
		foreach ( $map as $post_id => $test ) {
			$variants = array();
			foreach ( $test->variants as $variant ) {
				$variants[] = array(
					'id'         => (int) $variant->id,
					'headline'   => $variant->headline,
					'is_control' => (bool) (int) $variant->is_control,
				);
			}

			$payload[ (string) $post_id ] = array(
				'test_id'          => (int) $test->id,
				'post_id'          => (int) $post_id,
				'scroll_threshold' => (float) $test->scroll_threshold,
				'time_threshold'   => (int) $test->time_threshold,
				'variants'         => $variants,
			);
		}

		return new WP_REST_Response(
			array(
				'tests' => $payload,
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function post_events( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || empty( $body['events'] ) || ! is_array( $body['events'] ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}

		$visitor_id = isset( $body['visitor_id'] ) ? sanitize_text_field( (string) $body['visitor_id'] ) : '';
		if ( strlen( $visitor_id ) < 8 || strlen( $visitor_id ) > 64 ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}

		$recorded = 0;
		foreach ( $body['events'] as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$test_id    = isset( $event['test_id'] ) ? (int) $event['test_id'] : 0;
			$variant_id = isset( $event['variant_id'] ) ? (int) $event['variant_id'] : 0;
			$type       = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : '';

			if ( AHT_Test_Repository::record_event( $test_id, $variant_id, $type, $visitor_id ) ) {
				++$recorded;
			}
		}

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'recorded' => $recorded,
			),
			200
		);
	}
}
