<?php
/**
 * Admin UI: meta box, list table, reports.
 *
 * @package AndreianHeadlineTesting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AHT_Admin {

	const MENU_SLUG = 'aht-headline-tests';
	const NONCE     = 'aht_admin_nonce';

	/**
	 * Register admin hooks.
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'handle_post_save' ), 10, 2 );
		add_action( 'admin_post_aht_start_test', array( __CLASS__, 'handle_start_test' ) );
		add_action( 'admin_post_aht_cancel_test', array( __CLASS__, 'handle_cancel_test' ) );
		add_action( 'admin_post_aht_complete_test', array( __CLASS__, 'handle_complete_test' ) );
		add_action( 'admin_post_aht_create_test', array( __CLASS__, 'handle_create_test' ) );
		add_filter( 'manage_post_posts_columns', array( __CLASS__, 'posts_column' ) );
		add_filter( 'manage_page_posts_columns', array( __CLASS__, 'posts_column' ) );
		add_action( 'manage_post_posts_custom_column', array( __CLASS__, 'posts_column_content' ), 10, 2 );
		add_action( 'manage_page_posts_custom_column', array( __CLASS__, 'posts_column_content' ), 10, 2 );
	}

	/**
	 * Capability check.
	 *
	 * @return bool
	 */
	protected static function can_manage() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Admin menu pages.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Headline Tests', 'andreian-headline-testing' ),
			__( 'Headline Tests', 'andreian-headline-testing' ),
			'edit_posts',
			self::MENU_SLUG,
			array( __CLASS__, 'render_list_page' ),
			'dashicons-randomize',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'All Tests', 'andreian-headline-testing' ),
			__( 'All Tests', 'andreian-headline-testing' ),
			'edit_posts',
			self::MENU_SLUG,
			array( __CLASS__, 'render_list_page' )
		);
	}

	/**
	 * Meta box for posts and pages.
	 */
	public static function register_meta_box() {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			add_meta_box(
				'aht-headline-test',
				__( 'Headline A/B Test', 'andreian-headline-testing' ),
				array( __CLASS__, 'render_meta_box' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * @param WP_Post $post Post.
	 */
	public static function render_meta_box( $post ) {
		if ( ! self::can_manage() ) {
			return;
		}

		wp_nonce_field( self::NONCE, 'aht_nonce' );

		$test = AHT_Test_Repository::get_latest_test_for_post( (int) $post->ID );

		if ( ! $test ) {
			$create_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=aht_create_test&post_id=' . (int) $post->ID ),
				self::NONCE
			);
			echo '<p>' . esc_html__( 'No headline test yet for this content.', 'andreian-headline-testing' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( $create_url ) . '">' . esc_html__( 'Create headline test', 'andreian-headline-testing' ) . '</a></p>';
			return;
		}

		$status = (string) $test->status;
		echo '<p><strong>' . esc_html__( 'Status', 'andreian-headline-testing' ) . ':</strong> ' . esc_html( ucfirst( $status ) ) . '</p>';

		if ( AHT_Test_Repository::STATUS_DRAFT === $status ) {
			self::render_draft_form( $test );
		} else {
			self::render_report_snippet( $test );
			self::render_running_actions( $test );
			if ( in_array( $status, array( AHT_Test_Repository::STATUS_COMPLETED, AHT_Test_Repository::STATUS_CANCELLED ), true ) ) {
				$create_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=aht_create_test&post_id=' . (int) $post->ID ),
					self::NONCE
				);
				echo '<p><a class="button" href="' . esc_url( $create_url ) . '">' . esc_html__( 'Start a new test', 'andreian-headline-testing' ) . '</a></p>';
			}
		}

		$report_url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&test_id=' . (int) $test->id );
		echo '<p><a href="' . esc_url( $report_url ) . '">' . esc_html__( 'Open full report', 'andreian-headline-testing' ) . '</a></p>';
	}

	/**
	 * @param object $test Test.
	 */
	protected static function render_draft_form( $test ) {
		$variants = array();
		foreach ( $test->variants as $variant ) {
			if ( ! (int) $variant->is_control ) {
				$variants[] = $variant->headline;
			}
		}
		if ( ! $variants ) {
			$variants = array( '' );
		}
		?>
		<p class="description"><? esc_html_e( 'Control uses the current post title when the test starts. Add one or more variations below.', 'andreian-headline-testing' ); ?></p>
		<table class="form-table aht-meta-form">
			<tr>
				<th scope="row"><? esc_html_e( 'Variations', 'andreian-headline-testing' ); ?></th>
				<td>
					<div id="aht-variations">
						<?php foreach ( $variants as $index => $headline ) : ?>
							<p><input type="text" class="widefat" name="aht_variants[]" value="<?php echo esc_attr( $headline ); ?>" placeholder="<? esc_attr_e( 'Headline variation', 'andreian-headline-testing' ); ?>" /></p>
						<?php endforeach; ?>
					</div>
					<p><button type="button" class="button" id="aht-add-variation"><? esc_html_e( 'Add variation', 'andreian-headline-testing' ); ?></button></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><? esc_html_e( 'Scroll threshold (%)', 'andreian-headline-testing' ); ?></th>
				<td><input type="number" step="1" min="1" max="100" name="aht_scroll_threshold" value="<?php echo esc_attr( $test->scroll_threshold ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><? esc_html_e( 'Time on content (sec)', 'andreian-headline-testing' ); ?></th>
				<td><input type="number" step="1" min="1" name="aht_time_threshold" value="<?php echo esc_attr( $test->time_threshold ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><? esc_html_e( 'Min impressions / variant', 'andreian-headline-testing' ); ?></th>
				<td><input type="number" step="1" min="10" name="aht_min_impressions" value="<?php echo esc_attr( $test->min_impressions ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><? esc_html_e( 'Auto-win confidence (%)', 'andreian-headline-testing' ); ?></th>
				<td><input type="number" step="0.1" min="50" max="99.9" name="aht_confidence_level" value="<?php echo esc_attr( $test->confidence_level ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><? esc_html_e( 'Min lift vs control (%)', 'andreian-headline-testing' ); ?></th>
				<td><input type="number" step="0.1" min="0" name="aht_min_lift" value="<?php echo esc_attr( $test->min_lift ); ?>" /></td>
			</tr>
		</table>
		<input type="hidden" name="aht_test_id" value="<?php echo esc_attr( (int) $test->id ); ?>" />
		<p><? esc_html_e( 'Update the post to save draft settings, then start the test from the link below.', 'andreian-headline-testing' ); ?></p>
		<?php
		$start_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=aht_start_test&test_id=' . (int) $test->id . '&post_id=' . (int) $test->post_id ),
			self::NONCE
		);
		echo '<p><a class="button button-primary" href="' . esc_url( $start_url ) . '">' . esc_html__( 'Start test', 'andreian-headline-testing' ) . '</a></p>';
		?>
		<script>
		(function () {
			var btn = document.getElementById('aht-add-variation');
			var wrap = document.getElementById('aht-variations');
			if (!btn || !wrap) return;
			btn.addEventListener('click', function () {
				var p = document.createElement('p');
				var input = document.createElement('input');
				input.type = 'text';
				input.className = 'widefat';
				input.name = 'aht_variants[]';
				input.placeholder = <?php echo wp_json_encode( __( 'Headline variation', 'andreian-headline-testing' ) ); ?>;
				p.appendChild(input);
				wrap.appendChild(p);
			});
		})();
		</script>
		<?php
	}

	/**
	 * @param object $test Test.
	 */
	protected static function render_report_snippet( $test ) {
		$rows = AHT_Statistics::build_variant_report( $test );
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Headline', 'andreian-headline-testing' ) . '</th>';
		echo '<th>' . esc_html__( 'Impr.', 'andreian-headline-testing' ) . '</th>';
		echo '<th>' . esc_html__( 'Engaged', 'andreian-headline-testing' ) . '</th>';
		echo '<th>' . esc_html__( 'Rate', 'andreian-headline-testing' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%1$s%2$s</td><td>%3$d</td><td>%4$d</td><td>%5$s</td></tr>',
				$row['is_control'] ? '<em>' . esc_html__( 'Control: ', 'andreian-headline-testing' ) . '</em>' : '',
				esc_html( wp_html_excerpt( $row['headline'], 80, '…' ) ),
				(int) $row['impressions'],
				(int) $row['engaged_visitors'],
				esc_html( number_format_i18n( 100 * $row['conversion_rate'], 1 ) . '%' )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * @param object $test Test.
	 */
	protected static function render_running_actions( $test ) {
		if ( AHT_Test_Repository::STATUS_RUNNING !== $test->status ) {
			return;
		}

		$cancel_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=aht_cancel_test&test_id=' . (int) $test->id . '&post_id=' . (int) $test->post_id ),
			self::NONCE
		);
		echo '<p><a class="button" href="' . esc_url( $cancel_url ) . '">' . esc_html__( 'Stop test (keep title)', 'andreian-headline-testing' ) . '</a></p>';

		echo '<p><strong>' . esc_html__( 'Declare winner', 'andreian-headline-testing' ) . '</strong></p><ul>';
		foreach ( $test->variants as $variant ) {
			$url = wp_nonce_url(
				admin_url(
					'admin-post.php?action=aht_complete_test&test_id=' . (int) $test->id . '&variant_id=' . (int) $variant->id . '&post_id=' . (int) $test->post_id
				),
				self::NONCE
			);
			$label = (int) $variant->is_control
				? __( 'Control (current title)', 'andreian-headline-testing' )
				: wp_html_excerpt( $variant->headline, 60, '…' );
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
		}
		echo '</ul>';
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post.
	 */
	public static function handle_post_save( $post_id, $post ) {
		if ( ! self::can_manage() ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['aht_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aht_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}
		if ( empty( $_POST['aht_test_id'] ) ) {
			return;
		}

		$test_id = (int) $_POST['aht_test_id'];
		$test    = AHT_Test_Repository::get_test( $test_id );
		if ( ! $test || (int) $test->post_id !== (int) $post_id ) {
			return;
		}

		$variants = isset( $_POST['aht_variants'] ) ? (array) wp_unslash( $_POST['aht_variants'] ) : array();

		AHT_Test_Repository::save_test(
			$test_id,
			array(
				'scroll_threshold' => isset( $_POST['aht_scroll_threshold'] ) ? (float) wp_unslash( $_POST['aht_scroll_threshold'] ) : 30,
				'time_threshold'   => isset( $_POST['aht_time_threshold'] ) ? (int) wp_unslash( $_POST['aht_time_threshold'] ) : 78,
				'min_impressions'  => isset( $_POST['aht_min_impressions'] ) ? (int) wp_unslash( $_POST['aht_min_impressions'] ) : 200,
				'confidence_level' => isset( $_POST['aht_confidence_level'] ) ? (float) wp_unslash( $_POST['aht_confidence_level'] ) : 95,
				'min_lift'         => isset( $_POST['aht_min_lift'] ) ? (float) wp_unslash( $_POST['aht_min_lift'] ) : 5,
				'variants'         => array_map( 'sanitize_text_field', $variants ),
			)
		);
	}

	/**
	 * Redirect helper.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $notice Query arg.
	 */
	protected static function redirect_to_post( $post_id, $notice = '' ) {
		$url = get_edit_post_link( (int) $post_id, 'raw' );
		if ( $notice ) {
			$url = add_query_arg( 'aht_notice', $notice, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	public static function handle_create_test() {
		if ( ! self::can_manage() || ! isset( $_GET['post_id'] ) ) {
			wp_die( esc_html__( 'Forbidden', 'andreian-headline-testing' ) );
		}
		check_admin_referer( self::NONCE );
		$post_id = (int) $_GET['post_id'];
		AHT_Test_Repository::create_draft_for_post( $post_id );
		self::redirect_to_post( $post_id, 'created' );
	}

	public static function handle_start_test() {
		if ( ! self::can_manage() || empty( $_GET['test_id'] ) ) {
			wp_die( esc_html__( 'Forbidden', 'andreian-headline-testing' ) );
		}
		check_admin_referer( self::NONCE );
		$test_id = (int) $_GET['test_id'];
		$post_id = (int) ( $_GET['post_id'] ?? 0 );
		$result  = AHT_Test_Repository::start_test( $test_id );
		if ( is_wp_error( $result ) ) {
			self::redirect_to_post( $post_id, 'error_' . $result->get_error_code() );
		}
		self::redirect_to_post( $post_id, 'started' );
	}

	public static function handle_cancel_test() {
		if ( ! self::can_manage() || empty( $_GET['test_id'] ) ) {
			wp_die( esc_html__( 'Forbidden', 'andreian-headline-testing' ) );
		}
		check_admin_referer( self::NONCE );
		$test_id = (int) $_GET['test_id'];
		$post_id = (int) ( $_GET['post_id'] ?? 0 );
		AHT_Test_Repository::cancel_test( $test_id );
		self::redirect_to_post( $post_id, 'cancelled' );
	}

	public static function handle_complete_test() {
		if ( ! self::can_manage() || empty( $_GET['test_id'] ) || empty( $_GET['variant_id'] ) ) {
			wp_die( esc_html__( 'Forbidden', 'andreian-headline-testing' ) );
		}
		check_admin_referer( self::NONCE );
		$test_id    = (int) $_GET['test_id'];
		$variant_id = (int) $_GET['variant_id'];
		$post_id    = (int) ( $_GET['post_id'] ?? 0 );
		AHT_Test_Repository::complete_test( $test_id, $variant_id );
		self::redirect_to_post( $post_id, 'completed' );
	}

	/**
	 * List / report admin page.
	 */
	public static function render_list_page() {
		if ( ! self::can_manage() ) {
			return;
		}

		if ( ! empty( $_GET['test_id'] ) ) {
			self::render_single_report( (int) $_GET['test_id'] );
			return;
		}

		$page   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$limit  = 20;
		$offset = ( $page - 1 ) * $limit;
		$tests  = AHT_Test_Repository::list_tests( $limit, $offset );
		$total  = AHT_Test_Repository::count_tests();

		echo '<div class="wrap"><h1>' . esc_html__( 'Headline Tests', 'andreian-headline-testing' ) . '</h1>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'andreian-headline-testing' ) . '</th>';
		echo '<th>' . esc_html__( 'Content', 'andreian-headline-testing' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'andreian-headline-testing' ) . '</th>';
		echo '<th>' . esc_html__( 'Started', 'andreian-headline-testing' ) . '</th>';
		echo '<th>' . esc_html__( 'Report', 'andreian-headline-testing' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $tests as $test ) {
			$post = get_post( (int) $test->post_id );
			$title = $post ? $post->post_title : '#' . (int) $test->post_id;
			$report_url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&test_id=' . (int) $test->id );
			$export_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=aht_export_csv&test_id=' . (int) $test->id ),
				AHT_CSV_Export::NONCE
			);
			echo '<tr>';
			echo '<td>' . esc_html( (int) $test->id ) . '</td>';
			echo '<td><a href="' . esc_url( get_edit_post_link( (int) $test->post_id ) ) . '">' . esc_html( $title ) . '</a></td>';
			echo '<td>' . esc_html( ucfirst( (string) $test->status ) ) . '</td>';
			echo '<td>' . esc_html( $test->started_at ? $test->started_at : '—' ) . '</td>';
			echo '<td><a href="' . esc_url( $report_url ) . '">' . esc_html__( 'View', 'andreian-headline-testing' ) . '</a> · ';
			echo '<a href="' . esc_url( $export_url ) . '">' . esc_html__( 'CSV', 'andreian-headline-testing' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$pages = (int) ceil( $total / $limit );
		if ( $pages > 1 ) {
			echo '<p class="tablenav">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				$url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&paged=' . $i );
				if ( $i === $page ) {
					echo '<strong> ' . esc_html( (string) $i ) . ' </strong>';
				} else {
					echo ' <a href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a> ';
				}
			}
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * @param int $test_id Test ID.
	 */
	protected static function render_single_report( $test_id ) {
		$test = AHT_Test_Repository::get_test( $test_id );
		if ( ! $test ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Test not found.', 'andreian-headline-testing' ) . '</p></div>';
			return;
		}

		$post  = get_post( (int) $test->post_id );
		$rows  = AHT_Statistics::build_variant_report( $test );
		$back  = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$export = wp_nonce_url(
			admin_url( 'admin-post.php?action=aht_export_csv&test_id=' . (int) $test->id ),
			AHT_CSV_Export::NONCE
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Headline test report', 'andreian-headline-testing' ) . '</h1>';
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'All tests', 'andreian-headline-testing' ) . '</a></p>';
		if ( $post ) {
			echo '<p><strong>' . esc_html__( 'Content', 'andreian-headline-testing' ) . ':</strong> ';
			echo '<a href="' . esc_url( get_edit_post_link( $post ) ) . '">' . esc_html( $post->post_title ) . '</a></p>';
		}
		echo '<p><strong>' . esc_html__( 'Status', 'andreian-headline-testing' ) . ':</strong> ' . esc_html( ucfirst( (string) $test->status ) ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Download CSV', 'andreian-headline-testing' ) . '</a></p>';

		echo '<table class="widefat striped"><thead><tr>';
		$headers = array(
			__( 'Headline', 'andreian-headline-testing' ),
			__( 'Impressions', 'andreian-headline-testing' ),
			__( 'Clicks', 'andreian-headline-testing' ),
			__( 'Scroll', 'andreian-headline-testing' ),
			__( 'Time', 'andreian-headline-testing' ),
			__( 'Engaged visitors', 'andreian-headline-testing' ),
			__( 'Engagement rate', 'andreian-headline-testing' ),
			__( 'Lift vs control', 'andreian-headline-testing' ),
			__( 'Confidence', 'andreian-headline-testing' ),
		);
		foreach ( $headers as $header ) {
			echo '<th>' . esc_html( $header ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . ( $row['is_control'] ? '<em>' . esc_html__( 'Control', 'andreian-headline-testing' ) . '</em> ' : '' ) . esc_html( $row['headline'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['impressions'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['clicks'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['scroll'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['time'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['engaged_visitors'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( 100 * $row['conversion_rate'], 2 ) . '%' ) . '</td>';
			echo '<td>' . ( null === $row['lift_vs_control'] ? '—' : esc_html( number_format_i18n( $row['lift_vs_control'], 1 ) . '%' ) ) . '</td>';
			echo '<td>' . ( null === $row['confidence'] ? '—' : esc_html( number_format_i18n( $row['confidence'], 1 ) . '%' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function posts_column( $columns ) {
		$columns['aht_test'] = 'A/B';
		return $columns;
	}

	/**
	 * @param string $column Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function posts_column_content( $column, $post_id ) {
		if ( 'aht_test' !== $column ) {
			return;
		}
		$running = AHT_Test_Repository::get_running_test_for_post( (int) $post_id );
		if ( $running ) {
			echo '<span class="dashicons dashicons-randomize" title="' . esc_attr__( 'Active headline test', 'andreian-headline-testing' ) . '"></span>';
		}
	}
}
