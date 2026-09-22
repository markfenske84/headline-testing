<?php
/**
 * Reporting metrics and significance.
 *
 * @package AndreianHeadlineTesting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AHT_Statistics {

	/**
	 * Build report rows for all variants in a test.
	 *
	 * @param object $test Test with variants.
	 * @return array<int, array<string, mixed>>
	 */
	public static function build_variant_report( $test ) {
		$stats    = AHT_Test_Repository::get_variant_stats( (int) $test->id );
		$control  = null;
		$rows     = array();

		foreach ( $test->variants as $variant ) {
			$vid     = (int) $variant->id;
			$counts  = $stats[ $vid ] ?? array(
				'impression' => 0,
				'click'      => 0,
				'scroll'     => 0,
				'time'       => 0,
				'engagement' => 0,
			);
			$engaged = AHT_Test_Repository::count_engaged_visitors( (int) $test->id, $vid );
			$imps    = (int) $counts['impression'];
			$rate    = $imps > 0 ? $engaged / $imps : 0.0;

			$row = array(
				'variant_id'       => $vid,
				'headline'         => $variant->headline,
				'is_control'       => (bool) (int) $variant->is_control,
				'impressions'      => $imps,
				'clicks'           => (int) $counts['click'],
				'scroll'           => (int) $counts['scroll'],
				'time'             => (int) $counts['time'],
				'engagement_events'=> (int) $counts['engagement'],
				'engaged_visitors' => $engaged,
				'conversion_rate'  => $rate,
				'lift_vs_control'  => null,
				'confidence'       => null,
			);

			if ( $row['is_control'] ) {
				$control = $row;
			}

			$rows[ $vid ] = $row;
		}

		if ( $control && $control['impressions'] > 0 ) {
			$control_rate = $control['conversion_rate'];
			foreach ( $rows as $vid => $row ) {
				if ( $row['is_control'] ) {
					continue;
				}
				if ( $control_rate > 0 ) {
					$rows[ $vid ]['lift_vs_control'] = ( ( $row['conversion_rate'] - $control_rate ) / $control_rate ) * 100;
				} elseif ( $row['conversion_rate'] > 0 ) {
					$rows[ $vid ]['lift_vs_control'] = 100.0;
				} else {
					$rows[ $vid ]['lift_vs_control'] = 0.0;
				}
				$rows[ $vid ]['confidence'] = self::two_proportion_confidence(
					$control['engaged_visitors'],
					$control['impressions'],
					$row['engaged_visitors'],
					$row['impressions']
				);
			}
		}

		return $rows;
	}

	/**
	 * Approximate one-sided confidence that variant beats control (percent).
	 *
	 * @param int $success_a Control engaged.
	 * @param int $total_a Control impressions.
	 * @param int $success_b Variant engaged.
	 * @param int $total_b Variant impressions.
	 * @return float|null
	 */
	public static function two_proportion_confidence( $success_a, $total_a, $success_b, $total_b ) {
		if ( $total_a < 1 || $total_b < 1 ) {
			return null;
		}

		$p1 = $success_a / $total_a;
		$p2 = $success_b / $total_b;
		$p  = ( $success_a + $success_b ) / ( $total_a + $total_b );
		$se = sqrt( $p * ( 1 - $p ) * ( ( 1 / $total_a ) + ( 1 / $total_b ) ) );

		if ( $se <= 0 ) {
			return null;
		}

		$z = ( $p2 - $p1 ) / $se;
		return self::normal_cdf( $z ) * 100;
	}

	/**
	 * Standard normal CDF approximation.
	 *
	 * @param float $z Z-score.
	 * @return float
	 */
	protected static function normal_cdf( $z ) {
		$t = 1 / ( 1 + 0.2316419 * abs( $z ) );
		$d = 0.3989423 * exp( -$z * $z / 2 );
		$p = $d * $t * ( 0.3193815 + $t * ( -0.3565638 + $t * ( 1.781478 + $t * ( -1.821256 + $t * 1.330274 ) ) ) );
		if ( $z > 0 ) {
			return 1 - $p;
		}
		return $p;
	}

	/**
	 * Pick best eligible variant for auto-winner (not control).
	 *
	 * @param object $test Test object.
	 * @return int|null Variant ID.
	 */
	public static function pick_auto_winner_variant_id( $test ) {
		$rows    = self::build_variant_report( $test );
		$control = null;
		foreach ( $rows as $row ) {
			if ( $row['is_control'] ) {
				$control = $row;
				break;
			}
		}

		if ( ! $control ) {
			return null;
		}

		$min_impressions = (int) $test->min_impressions;
		$min_confidence  = (float) $test->confidence_level;
		$min_lift        = (float) $test->min_lift;

		$best_id    = null;
		$best_rate  = -1.0;

		foreach ( $rows as $row ) {
			if ( $row['is_control'] ) {
				continue;
			}
			if ( $row['impressions'] < $min_impressions || $control['impressions'] < $min_impressions ) {
				continue;
			}
			if ( null === $row['confidence'] || $row['confidence'] < $min_confidence ) {
				continue;
			}
			if ( null === $row['lift_vs_control'] || $row['lift_vs_control'] < $min_lift ) {
				continue;
			}
			if ( $row['conversion_rate'] > $best_rate ) {
				$best_rate = $row['conversion_rate'];
				$best_id   = (int) $row['variant_id'];
			}
		}

		return $best_id;
	}
}
