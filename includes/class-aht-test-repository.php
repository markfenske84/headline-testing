<?php
/**
 * Test and variant persistence.
 *
 * @package AndreianHeadlineTesting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AHT_Test_Repository {

	const STATUS_DRAFT     = 'draft';
	const STATUS_RUNNING   = 'running';
	const STATUS_COMPLETED = 'completed';
	const STATUS_CANCELLED = 'cancelled';

	const EVENT_IMPRESSION = 'impression';
	const EVENT_CLICK      = 'click';
	const EVENT_SCROLL     = 'scroll';
	const EVENT_TIME       = 'time';

	/**
	 * @return string
	 */
	public static function tests_table() {
		global $wpdb;
		return $wpdb->prefix . 'aht_tests';
	}

	/**
	 * @return string
	 */
	public static function variants_table() {
		global $wpdb;
		return $wpdb->prefix . 'aht_variants';
	}

	/**
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'aht_events';
	}

	/**
	 * @param int $test_id Test ID.
	 * @return object|null
	 */
	public static function get_test( $test_id ) {
		global $wpdb;

		$test = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tests_table() . ' WHERE id = %d',
				(int) $test_id
			)
		);

		if ( $test ) {
			$test->variants = self::get_variants_for_test( (int) $test->id );
		}

		return $test;
	}

	/**
	 * @param int $test_id Test ID.
	 * @return array
	 */
	public static function get_variants_for_test( $test_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::variants_table() . ' WHERE test_id = %d ORDER BY sort_order ASC, id ASC',
				(int) $test_id
			)
		);
	}

	/**
	 * Running test for a post, if any.
	 *
	 * @param int $post_id Post ID.
	 * @return object|null
	 */
	public static function get_running_test_for_post( $post_id ) {
		global $wpdb;

		$test = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tests_table() . ' WHERE post_id = %d AND status = %s ORDER BY id DESC LIMIT 1',
				(int) $post_id,
				self::STATUS_RUNNING
			)
		);

		if ( $test ) {
			$test->variants = self::get_variants_for_test( (int) $test->id );
		}

		return $test;
	}

	/**
	 * Latest test row for post (any status).
	 *
	 * @param int $post_id Post ID.
	 * @return object|null
	 */
	public static function get_latest_test_for_post( $post_id ) {
		global $wpdb;

		$test = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tests_table() . ' WHERE post_id = %d ORDER BY id DESC LIMIT 1',
				(int) $post_id
			)
		);

		if ( $test ) {
			$test->variants = self::get_variants_for_test( (int) $test->id );
		}

		return $test;
	}

	/**
	 * @param int[] $post_ids Post IDs.
	 * @return array<int, object> Map post_id => test with variants.
	 */
	public static function get_running_tests_for_posts( array $post_ids ) {
		global $wpdb;

		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		if ( ! $post_ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$sql          = 'SELECT * FROM ' . self::tests_table() . ' WHERE status = %s AND post_id IN (' . $placeholders . ')';
		$prepare      = array_merge( array( self::STATUS_RUNNING ), $post_ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built safely.
		$tests = $wpdb->get_results( $wpdb->prepare( $sql, $prepare ) );

		$map = array();
		foreach ( $tests as $test ) {
			$test->variants = self::get_variants_for_test( (int) $test->id );
			$map[ (int) $test->post_id ] = $test;
		}

		return $map;
	}

	/**
	 * @param int $limit Limit.
	 * @param int $offset Offset.
	 * @return array
	 */
	public static function list_tests( $limit = 50, $offset = 0 ) {
		global $wpdb;

		$limit  = max( 1, (int) $limit );
		$offset = max( 0, (int) $offset );

		$tests = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tests_table() . ' ORDER BY id DESC LIMIT %d OFFSET %d',
				$limit,
				$offset
			)
		);

		foreach ( $tests as $test ) {
			$test->variants = self::get_variants_for_test( (int) $test->id );
		}

		return $tests;
	}

	/**
	 * @return int
	 */
	public static function count_tests() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::tests_table() );
	}

	/**
	 * Create draft test with control from post title.
	 *
	 * @param int $post_id Post ID.
	 * @return int|false Test ID.
	 */
	public static function create_draft_for_post( $post_id ) {
		global $wpdb;

		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return false;
		}

		$running = self::get_running_test_for_post( $post_id );
		if ( $running ) {
			return false;
		}

		$now = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			self::tests_table(),
			array(
				'post_id'           => $post_id,
				'status'            => self::STATUS_DRAFT,
				'control_title'     => $post->post_title,
				'scroll_threshold'  => 30,
				'time_threshold'    => 78,
				'min_impressions'   => 200,
				'confidence_level'  => 95,
				'min_lift'          => 5,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%d', '%s', '%s', '%f', '%d', '%d', '%f', '%f', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		$test_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			self::variants_table(),
			array(
				'test_id'    => $test_id,
				'headline'   => $post->post_title,
				'is_control' => 1,
				'sort_order' => 0,
			),
			array( '%d', '%s', '%d', '%d' )
		);

		return $test_id;
	}

	/**
	 * Save test settings and variant headlines (draft only).
	 *
	 * @param int   $test_id Test ID.
	 * @param array $data Settings and variants.
	 * @return bool
	 */
	public static function save_test( $test_id, array $data ) {
		global $wpdb;

		$test = self::get_test( $test_id );
		if ( ! $test || self::STATUS_DRAFT !== $test->status ) {
			return false;
		}

		$now = current_time( 'mysql', true );

		$wpdb->update(
			self::tests_table(),
			array(
				'scroll_threshold' => (float) ( $data['scroll_threshold'] ?? $test->scroll_threshold ),
				'time_threshold'   => (int) ( $data['time_threshold'] ?? $test->time_threshold ),
				'min_impressions'  => (int) ( $data['min_impressions'] ?? $test->min_impressions ),
				'confidence_level' => (float) ( $data['confidence_level'] ?? $test->confidence_level ),
				'min_lift'         => (float) ( $data['min_lift'] ?? $test->min_lift ),
				'updated_at'       => $now,
			),
			array( 'id' => (int) $test_id ),
			array( '%f', '%d', '%d', '%f', '%f', '%s' ),
			array( '%d' )
		);

		if ( isset( $data['variants'] ) && is_array( $data['variants'] ) ) {
			self::replace_variants( (int) $test_id, $data['variants'], (string) $test->control_title );
		}

		return true;
	}

	/**
	 * @param int    $test_id Test ID.
	 * @param array  $variant_headlines Non-control headlines (indexed).
	 * @param string $control_title Control headline.
	 */
	protected static function replace_variants( $test_id, array $variant_headlines, $control_title ) {
		global $wpdb;

		$wpdb->delete( self::variants_table(), array( 'test_id' => $test_id ), array( '%d' ) );

		$wpdb->insert(
			self::variants_table(),
			array(
				'test_id'    => $test_id,
				'headline'   => $control_title,
				'is_control' => 1,
				'sort_order' => 0,
			),
			array( '%d', '%s', '%d', '%d' )
		);

		$order = 1;
		foreach ( $variant_headlines as $headline ) {
			$headline = trim( (string) $headline );
			if ( '' === $headline ) {
				continue;
			}
			$wpdb->insert(
				self::variants_table(),
				array(
					'test_id'    => $test_id,
					'headline'   => $headline,
					'is_control' => 0,
					'sort_order' => $order,
				),
				array( '%d', '%s', '%d', '%d' )
			);
			++$order;
		}
	}

	/**
	 * Start a draft test.
	 *
	 * @param int $test_id Test ID.
	 * @return true|WP_Error
	 */
	public static function start_test( $test_id ) {
		global $wpdb;

		$test = self::get_test( $test_id );
		if ( ! $test || self::STATUS_DRAFT !== $test->status ) {
			return new WP_Error( 'aht_invalid', __( 'Test cannot be started.', 'andreian-headline-testing' ) );
		}

		$variants = array_filter(
			$test->variants,
			static function ( $v ) {
				return ! (int) $v->is_control;
			}
		);

		if ( count( $variants ) < 1 ) {
			return new WP_Error( 'aht_no_variants', __( 'Add at least one headline variation.', 'andreian-headline-testing' ) );
		}

		$existing = self::get_running_test_for_post( (int) $test->post_id );
		if ( $existing && (int) $existing->id !== (int) $test_id ) {
			return new WP_Error( 'aht_running', __( 'Another test is already running for this post.', 'andreian-headline-testing' ) );
		}

		$post = get_post( (int) $test->post_id );
		if ( $post ) {
			$wpdb->update(
				self::tests_table(),
				array(
					'control_title' => $post->post_title,
					'updated_at'    => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $test_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			foreach ( $test->variants as $variant ) {
				if ( (int) $variant->is_control ) {
					$wpdb->update(
						self::variants_table(),
						array( 'headline' => $post->post_title ),
						array( 'id' => (int) $variant->id ),
						array( '%s' ),
						array( '%d' )
					);
				}
			}
		}

		$now = current_time( 'mysql', true );
		$wpdb->update(
			self::tests_table(),
			array(
				'status'     => self::STATUS_RUNNING,
				'started_at' => $now,
				'updated_at' => $now,
			),
			array( 'id' => (int) $test_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		self::purge_post_cache( (int) $test->post_id );

		return true;
	}

	/**
	 * Cancel a running test without changing the post title.
	 *
	 * @param int $test_id Test ID.
	 * @return bool
	 */
	public static function cancel_test( $test_id ) {
		global $wpdb;

		$test = self::get_test( $test_id );
		if ( ! $test || self::STATUS_RUNNING !== $test->status ) {
			return false;
		}

		$now = current_time( 'mysql', true );
		$wpdb->update(
			self::tests_table(),
			array(
				'status'       => self::STATUS_CANCELLED,
				'completed_at' => $now,
				'updated_at'   => $now,
			),
			array( 'id' => (int) $test_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		self::purge_post_cache( (int) $test->post_id );

		return true;
	}

	/**
	 * Complete test and optionally promote winner to post title.
	 *
	 * @param int      $test_id Test ID.
	 * @param int|null $winner_variant_id Winner variant ID.
	 * @return bool
	 */
	public static function complete_test( $test_id, $winner_variant_id = null ) {
		global $wpdb;

		$test = self::get_test( $test_id );
		if ( ! $test || self::STATUS_RUNNING !== $test->status ) {
			return false;
		}

		$winner_variant_id = $winner_variant_id ? (int) $winner_variant_id : null;
		if ( $winner_variant_id ) {
			$valid = false;
			foreach ( $test->variants as $variant ) {
				if ( (int) $variant->id === $winner_variant_id ) {
					$valid = true;
					break;
				}
			}
			if ( ! $valid ) {
				return false;
			}
		}

		$now = current_time( 'mysql', true );
		$wpdb->update(
			self::tests_table(),
			array(
				'status'            => self::STATUS_COMPLETED,
				'winner_variant_id' => $winner_variant_id,
				'completed_at'      => $now,
				'updated_at'        => $now,
			),
			array( 'id' => (int) $test_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( $winner_variant_id ) {
			foreach ( $test->variants as $variant ) {
				if ( (int) $variant->id === $winner_variant_id && '' !== trim( $variant->headline ) ) {
					wp_update_post(
						array(
							'ID'         => (int) $test->post_id,
							'post_title' => $variant->headline,
						)
					);
					break;
				}
			}
		}

		self::purge_post_cache( (int) $test->post_id );

		return true;
	}

	/**
	 * @param int    $test_id Test ID.
	 * @param int    $variant_id Variant ID.
	 * @param string $event_type Event type.
	 * @param string $visitor_id Raw visitor cookie value.
	 * @return bool True if recorded (new).
	 */
	public static function record_event( $test_id, $variant_id, $event_type, $visitor_id ) {
		global $wpdb;

		$allowed = array(
			self::EVENT_IMPRESSION,
			self::EVENT_CLICK,
			self::EVENT_SCROLL,
			self::EVENT_TIME,
		);

		if ( ! in_array( $event_type, $allowed, true ) ) {
			return false;
		}

		$test = self::get_test( $test_id );
		if ( ! $test || self::STATUS_RUNNING !== $test->status ) {
			return false;
		}

		$variant_ok = false;
		foreach ( $test->variants as $variant ) {
			if ( (int) $variant->id === (int) $variant_id ) {
				$variant_ok = true;
				break;
			}
		}
		if ( ! $variant_ok ) {
			return false;
		}

		$visitor_id   = sanitize_text_field( $visitor_id );
		$visitor_hash = hash( 'sha256', $test_id . '|' . $visitor_id );

		$now = current_time( 'mysql', true );
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::events_table() . ' (test_id, variant_id, event_type, visitor_hash, created_at) VALUES (%d, %d, %s, %s, %s)',
				(int) $test_id,
				(int) $variant_id,
				$event_type,
				$visitor_hash,
				$now
			)
		);

		return false !== $result && $wpdb->rows_affected > 0;
	}

	/**
	 * Aggregate counts per variant for a test.
	 *
	 * @param int $test_id Test ID.
	 * @return array<int, array<string, int>>
	 */
	public static function get_variant_stats( $test_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT variant_id, event_type, COUNT(*) AS cnt FROM ' . self::events_table() . ' WHERE test_id = %d GROUP BY variant_id, event_type',
				(int) $test_id
			)
		);

		$stats = array();
		foreach ( $rows as $row ) {
			$vid = (int) $row->variant_id;
			if ( ! isset( $stats[ $vid ] ) ) {
				$stats[ $vid ] = array(
					'impression' => 0,
					'click'      => 0,
					'scroll'     => 0,
					'time'       => 0,
					'engagement' => 0,
				);
			}
			$type = (string) $row->event_type;
			if ( isset( $stats[ $vid ][ $type ] ) ) {
				$stats[ $vid ][ $type ] = (int) $row->cnt;
			}
		}

		foreach ( $stats as $vid => $counts ) {
			$stats[ $vid ]['engagement'] = self::engagement_count_from_row( $counts );
		}

		return $stats;
	}

	/**
	 * Unique visitors with any engagement signal (deduped per visitor across signals).
	 *
	 * @param int $test_id Test ID.
	 * @param int $variant_id Variant ID.
	 * @return int
	 */
	public static function count_engaged_visitors( $test_id, $variant_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT visitor_hash) FROM ' . self::events_table() . ' WHERE test_id = %d AND variant_id = %d AND event_type IN (%s, %s, %s)',
				(int) $test_id,
				(int) $variant_id,
				self::EVENT_CLICK,
				self::EVENT_SCROLL,
				self::EVENT_TIME
			)
		);
	}

	/**
	 * @param array<string, int> $counts Counts row.
	 * @return int
	 */
	public static function engagement_count_from_row( array $counts ) {
		return (int) $counts['click'] + (int) $counts['scroll'] + (int) $counts['time'];
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public static function purge_post_cache( $post_id ) {
		clean_post_cache( $post_id );
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $post_id );
		}
	}

	/**
	 * @return array<int, object>
	 */
	public static function get_running_tests() {
		global $wpdb;

		$tests = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::tests_table() . ' WHERE status = %s',
				self::STATUS_RUNNING
			)
		);

		foreach ( $tests as $test ) {
			$test->variants = self::get_variants_for_test( (int) $test->id );
		}

		return $tests;
	}
}
