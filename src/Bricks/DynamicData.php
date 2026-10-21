<?php
/**
 * Dynamic data tags for Bricks.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks;

use OdooWooPortal\Api\Routes;
use OdooWooPortal\Cards\Renderer;
use OdooWooPortal\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Five tags about the viewer's license and the dashboard in the URL. Bricks has no public
 * provider API (providers.php:217), so these use the public filters: the picker list, the single
 * tag (called with the bare tag, and with the result of the theme's own handler, which for an
 * unknown tag is the braced tag, providers.php:772), and content.
 *
 * Everything comes from one Routes::license_summary() per license and request. A visitor, a
 * license that is not the viewer's, a dashboard that is not in that license's list and a failed
 * read all give ''.
 */
final class DynamicData {

	public const TAGS = array( 'odoowoo_dashboard_name', 'odoowoo_dashboard_state', 'odoowoo_plan_dashboards', 'odoowoo_plan_used', 'odoowoo_has_plan' );

	/**
	 * Hooks the filters.
	 */
	public static function register(): void {
		add_filter( 'bricks/dynamic_tags_list', array( __CLASS__, 'tags_list' ) );
		add_filter( 'bricks/dynamic_data/render_tag', array( __CLASS__, 'render_tag' ), 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', array( __CLASS__, 'render_content' ), 20, 3 );
		add_filter( 'bricks/frontend/render_data', array( __CLASS__, 'render_content' ), 20, 3 );
	}

	/**
	 * Adds the tags to the builder's picker (providers.php:1038).
	 *
	 * @param mixed $tags Tags so far.
	 * @return mixed
	 */
	public static function tags_list( $tags ) {
		if ( ! is_array( $tags ) ) {
			return $tags;
		}

		$labels = array(
			'odoowoo_dashboard_name'  => __( 'Dashboard name', 'odoowoo-portal' ),
			'odoowoo_dashboard_state' => __( 'Dashboard state', 'odoowoo-portal' ),
			'odoowoo_plan_dashboards' => __( 'Plan: dashboards allowed', 'odoowoo-portal' ),
			'odoowoo_plan_used'       => __( 'Plan: dashboards used', 'odoowoo-portal' ),
			'odoowoo_has_plan'        => __( 'Has an OdooWoo plan (1 or empty)', 'odoowoo-portal' ),
		);

		foreach ( self::TAGS as $name ) {
			$tags[] = array(
				'name'  => '{' . $name . '}',
				'label' => $labels[ $name ],
				'group' => 'OdooWoo',
			);
		}

		return $tags;
	}

	/**
	 * One tag. Anything that is not one of ours is returned untouched.
	 *
	 * @param mixed  $value   The tag, or what the theme made of it.
	 * @param mixed  $post    Post.
	 * @param string $context text, link or image.
	 * @return mixed
	 */
	public static function render_tag( $value, $post = null, $context = 'text' ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\{?(odoowoo_[a-z_]+)\}?$/', trim( $value ), $match ) || ! in_array( $match[1], self::TAGS, true ) ) {
			return $value;
		}

		return 'text' === $context ? self::safe( self::value( $match[1] ) ) : '';
	}

	/**
	 * Our tags inside a string; every other tag stays.
	 *
	 * @param mixed $content Content.
	 * @return mixed
	 */
	public static function render_content( $content, $post = null, $context = 'text' ) {
		if ( ! is_string( $content ) || false === strpos( $content, '{odoowoo_' ) ) {
			return $content;
		}

		return preg_replace_callback(
			'/\{(odoowoo_[a-z_]+)\}/',
			static function ( array $match ): string {
				return in_array( $match[1], self::TAGS, true ) ? self::safe( self::value( $match[1] ) ) : $match[0];
			},
			$content
		);
	}

	/**
	 * Escaped for HTML, and braces as entities so Bricks cannot read a tag out of a dashboard name
	 * (the theme does the same for :raw, providers.php:754).
	 *
	 * @param string $text Text.
	 */
	private static function safe( string $text ): string {
		return str_replace( array( '{', '}' ), array( '&#123;', '&#125;' ), esc_html( $text ) );
	}

	/**
	 * The value of a tag for the current viewer.
	 *
	 * @param string $tag Tag name.
	 */
	private static function value( string $tag ): string {
		$user = get_current_user_id();

		if ( $user <= 0 ) {
			return '';
		}

		$licenses = Context::licenses_for_user( $user );

		// Any usable license, as the condition: it is WordPress state, so no Django read.
		if ( 'odoowoo_has_plan' === $tag ) {
			return array() !== $licenses ? '1' : '';
		}

		$ids = Renderer::context_ids();

		// The list shows every license when the URL names none, so one license's numbers would mislead.
		if ( $ids['license'] <= 0 && count( $licenses ) > 1 && in_array( $tag, array( 'odoowoo_plan_used', 'odoowoo_plan_dashboards' ), true ) ) {
			return '';
		}

		$license_id = Context::page_license_id( $user, $ids['license'] );

		$ctx = $license_id > 0 ? Context::for_user( $user, $license_id ) : null;

		if ( null === $ctx ) {
			return '';
		}

		$summary = Routes::license_summary( $ctx );

		if ( isset( $summary['error'] ) ) {
			return '';
		}

		if ( 'odoowoo_plan_used' === $tag ) {
			return null === $summary['used'] ? '' : (string) (int) $summary['used'];
		}

		if ( 'odoowoo_plan_dashboards' === $tag ) {
			if ( null === $summary['limit'] ) {
				return '';
			}

			// Django: a limit of 0 means no limit.
			return 0 === (int) $summary['limit'] ? __( 'unlimited', 'odoowoo-portal' ) : (string) (int) $summary['limit'];
		}

		foreach ( (array) $summary['dashboards'] as $row ) {
			if ( ! is_array( $row ) || $ids['dashboard'] <= 0 || (int) ( $row['id'] ?? 0 ) !== $ids['dashboard'] ) {
				continue;
			}

			if ( 'odoowoo_dashboard_name' === $tag ) {
				return isset( $row['name'] ) && is_string( $row['name'] ) ? $row['name'] : '';
			}

			if ( ! isset( $row['enabled'] ) ) {
				return '';
			}

			return filter_var( $row['enabled'], FILTER_VALIDATE_BOOLEAN ) ? __( 'Active', 'odoowoo-portal' ) : __( 'Paused', 'odoowoo-portal' );
		}

		return '';
	}
}
