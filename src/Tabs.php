<?php
/**
 * Dashboard tabs.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal;

defined( 'ABSPATH' ) || exit;

/**
 * The tabs of a dashboard.
 */
final class Tabs {

	public const SLUGS = array( 'connections', 'mappings', 'gift-cards', 'sync', 'logs' );

	/**
	 * The tab when it is known, otherwise the first one.
	 *
	 * @param string $tab Requested tab.
	 */
	public static function valid( string $tab ): string {
		return in_array( $tab, self::SLUGS, true ) ? $tab : 'connections';
	}
}
