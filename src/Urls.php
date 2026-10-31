<?php
/**
 * Portal URLs.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal;

defined( 'ABSPATH' ) || exit;

/**
 * Links to the app's pages: the two Bricks pages, or the pages inside My Account when beltoft-services draws the app there.
 */
final class Urls {

	/**
	 * The dashboard list page, or the home page when none is set.
	 */
	public static function list(): string {
		return self::page( (int) Settings::get( 'list_page_id' ) );
	}

	/**
	 * The list page for one license.
	 *
	 * @param int $license_id License id.
	 */
	public static function license_list( int $license_id ): string {
		$own = Account\Area::service_url( $license_id );

		if ( '' !== $own ) {
			return $own;
		}

		return add_query_arg( array( 'license' => $license_id ), self::list() );
	}

	/**
	 * One dashboard's page, on a tab.
	 *
	 * @param int    $license_id   License id.
	 * @param int    $dashboard_id Dashboard id.
	 * @param string $tab          Tab; unknown ones become the first.
	 */
	public static function dashboard( int $license_id, int $dashboard_id, string $tab = 'connections' ): string {
		// Inside My Account when the app lives there for the viewer, else the dashboard page.
		$own = Account\Area::dashboard_url( $license_id, $dashboard_id, $tab );

		if ( '' !== $own ) {
			return $own;
		}

		return add_query_arg(
			array(
				'license'   => $license_id,
				'dashboard' => $dashboard_id,
				'tab'       => Tabs::valid( $tab ),
			),
			self::page( (int) Settings::get( 'dashboard_page_id' ) )
		);
	}

	/**
	 * A page's permalink, or the home page.
	 *
	 * @param int $page_id Page id.
	 */
	private static function page( int $page_id ): string {
		$url = $page_id > 0 ? get_permalink( $page_id ) : false;

		return is_string( $url ) && '' !== $url ? $url : home_url( '/' );
	}
}
