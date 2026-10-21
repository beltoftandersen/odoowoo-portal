<?php
/**
 * A test-only card: its template, its script URL and its registration.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests\Support;

/**
 * Registered through the odoowoo_portal_cards filter, so Task 8 can be tested before any real
 * card template exists.
 */
final class CardProbe {

	public const NAME = 'probe';

	/**
	 * Registers the card; returns the callback so the test can remove it.
	 */
	public static function register(): callable {
		$callback = static function ( $cards ) {
			$cards[ self::NAME ] = array(
				'title'    => 'Probe card',
				'template' => self::template(),
				'script'   => 'https://example.test/card-probe.js',
				'keys'     => array(),
				'strings'  => array( 'probeHello' => 'Hello from the probe' ),
			);

			return $cards;
		};

		add_filter( 'odoowoo_portal_cards', $callback );

		return $callback;
	}

	/**
	 * Absolute path of the template (written once).
	 */
	public static function template(): string {
		$path = sys_get_temp_dir() . '/odoowoo-portal-probe-template.php';

		if ( ! file_exists( $path ) ) {
			file_put_contents( $path, "<p class=\"probe-body\"><?php echo esc_html( 'Probe body' ); ?></p>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $path;
	}
}
