<?php
/**
 * The "Has an OdooWoo plan" element condition.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks;

use OdooWooPortal\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Bricks knows nothing of the key, so its compare switch runs with a value of false; the result
 * filter (conditions.php:1430) replaces that with the real answer. The builder never checks
 * conditions (conditions.php:1063), the frontend decides.
 */
final class Conditions {

	public const KEY = 'odoowoo_has_plan';

	/**
	 * Hooks the filters. The groups and options filters fire on wp_loaded (conditions.php:12).
	 */
	public static function register(): void {
		add_filter( 'bricks/conditions/groups', array( __CLASS__, 'groups' ) );
		add_filter( 'bricks/conditions/options', array( __CLASS__, 'options' ) );
		add_filter( 'bricks/conditions/result', array( __CLASS__, 'result' ), 10, 3 );
		add_filter( 'bricks/abilities/element_conditions/allowed_keys', array( __CLASS__, 'allowed_keys' ) );
	}

	/**
	 * The group (shape of conditions.php:50-56).
	 *
	 * @param mixed $groups Groups.
	 * @return mixed
	 */
	public static function groups( $groups ) {
		if ( is_array( $groups ) ) {
			$groups[] = array(
				'name'  => 'odoowoo',
				'label' => 'OdooWoo',
			);
		}

		return $groups;
	}

	/**
	 * The picker entry, shaped like user_logged_in (conditions.php:259-277).
	 *
	 * @param mixed $options Options.
	 * @return mixed
	 */
	public static function options( $options ) {
		if ( is_array( $options ) ) {
			$options[] = array(
				'key'     => self::KEY,
				'group'   => 'odoowoo',
				'label'   => __( 'Has an OdooWoo plan', 'odoowoo-portal' ),
				'compare' => array(
					'type'        => 'select',
					'options'     => array(
						'==' => __( 'is', 'odoowoo-portal' ),
						'!=' => __( 'is not', 'odoowoo-portal' ),
					),
					'placeholder' => __( 'is', 'odoowoo-portal' ),
				),
				'value'   => array(
					'type'        => 'select',
					'options'     => array(
						1 => __( 'Has a plan', 'odoowoo-portal' ),
						0 => __( 'Has no plan', 'odoowoo-portal' ),
					),
					'placeholder' => __( 'Select', 'odoowoo-portal' ),
				),
			);
		}

		return $options;
	}

	/**
	 * The answer for our key; every other key keeps the value Bricks worked out.
	 *
	 * Any usable license counts (active, suspended or expired keep the read-only app; revoked and
	 * other products do not): Context::licenses_for_user(). As in Bricks, a missing value means 0.
	 *
	 * @param mixed  $render_set Value so far.
	 * @param mixed  $key        Condition key.
	 * @param mixed  $condition  The condition.
	 * @return mixed
	 */
	public static function result( $render_set, $key = '', $condition = array() ) {
		if ( self::KEY !== $key || ! is_array( $condition ) ) {
			return $render_set;
		}

		$has     = DynamicData::has_plan( get_current_user_id() );
		$wanted  = isset( $condition['value'] ) && filter_var( $condition['value'], FILTER_VALIDATE_BOOLEAN );
		$compare = isset( $condition['compare'] ) ? $condition['compare'] : '==';

		return '!=' === $compare ? $has !== $wanted : $has === $wanted;
	}

	/**
	 * Lets the MCP's strict condition writer accept the key (element-conditions.php:572).
	 *
	 * @param mixed $keys Allowed keys.
	 * @return mixed
	 */
	public static function allowed_keys( $keys ) {
		if ( is_array( $keys ) && ! in_array( self::KEY, $keys, true ) ) {
			$keys[] = self::KEY;
		}

		return $keys;
	}
}
