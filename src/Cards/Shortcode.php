<?php
/**
 * [odoowoo_card name="..."].
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Cards;

defined( 'ABSPATH' ) || exit;

/**
 * The fallback for pages that are not built with the Bricks elements: the same markup as the
 * renderer gives.
 */
final class Shortcode {

	public const TAG = 'odoowoo_card';

	/**
	 * Registers the shortcode.
	 */
	public static function register(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Renders one card; an unknown name renders nothing.
	 *
	 * @param array<string, mixed>|string $atts Attributes.
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts( array( 'name' => '' ), is_array( $atts ) ? $atts : array(), self::TAG );
		$name = sanitize_key( (string) $atts['name'] );

		return Registry::has( $name ) ? Renderer::render( $name ) : '';
	}
}
