<?php
/**
 * Registers the card elements with Bricks.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Bricks;

use OdooWooPortal\Cards\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Bricks 2.4.3 loads its element base class on init priority 10 (includes/elements.php:13,19),
 * so the elements are registered on priority 11, by file path (elements.php:253), the way the
 * theme's own child theme does. Without Bricks nothing happens.
 */
final class Elements {

	/**
	 * Is Bricks' element registry there?
	 */
	public static function available(): bool {
		/**
		 * Whether to register the Bricks elements.
		 *
		 * @param bool $available True when Bricks' element classes exist.
		 */
		return (bool) apply_filters( 'odoowoo_portal_bricks_available', class_exists( '\Bricks\Elements' ) && class_exists( '\Bricks\Element' ) );
	}

	/**
	 * Registers one element per card that has an element file. Runs on init, priority 11.
	 */
	public static function register(): void {
		if ( ! self::available() ) {
			return;
		}

		add_filter( 'bricks/builder/i18n', array( __CLASS__, 'i18n' ) );

		// Loads the base class before the first element file is read, so the file's own class is the last one declared.
		if ( ! class_exists( Card_Element::class ) ) {
			return;
		}

		foreach ( array_keys( Registry::cards() ) as $card ) {
			$file = ODOOWOO_PORTAL_PATH . 'src/Bricks/elements/' . self::class_name( $card ) . '.php';

			if ( is_readable( $file ) ) {
				\Bricks\Elements::register_element( $file );
			}
		}
	}

	/**
	 * The builder's panel group title (builder.php:1905).
	 *
	 * @param array<string, string> $i18n Builder strings.
	 * @return array<string, string>
	 */
	public static function i18n( $i18n ) {
		if ( is_array( $i18n ) ) {
			$i18n['odoowoo'] = 'OdooWoo';
		}

		return $i18n;
	}

	/**
	 * Element class name of a card: dashboard-list => Dashboard_List.
	 *
	 * @param string $card Card name.
	 */
	public static function class_name( string $card ): string {
		return str_replace( ' ', '_', ucwords( str_replace( '-', ' ', $card ) ) );
	}
}
