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
	 * The tabs of a dashboard page inside My Account: slug => title.
	 *
	 * @return array<string, string>
	 */
	public static function titles(): array {
		return array(
			'connections' => __( 'Connections', 'odoowoo-portal' ),
			'mappings'    => __( 'Mappings', 'odoowoo-portal' ),
			'gift-cards'  => __( 'Gift cards', 'odoowoo-portal' ),
			'sync'        => __( 'Sync', 'odoowoo-portal' ),
			'logs'        => __( 'Logs', 'odoowoo-portal' ),
		);
	}

	/**
	 * The cards a tab holds on a dashboard page inside My Account (the Bricks page places its own).
	 *
	 * @param string $tab Tab.
	 * @return array<int, string> Card names.
	 */
	public static function cards( string $tab ): array {
		$tab   = self::valid( $tab );
		$cards = array(
			'connections' => array( 'woo-connection', 'odoo-connection', 'woo-settings', 'odoo-settings' ),
			'mappings'    => Cards\Mappings::CARDS,
			'gift-cards'  => array( 'gift-cards' ),
			'sync'        => array( 'manual-sync' ),
			'logs'        => array( 'sync-logs' ),
		);

		/**
		 * Filters the cards each tab of a dashboard page in My Account holds.
		 *
		 * @param array<string, array<int, string>> $cards Tab => card names.
		 */
		$filtered = apply_filters( 'odoowoo_portal_account_tab_cards', $cards );
		$filtered = is_array( $filtered ) ? $filtered : $cards;

		return array_values( array_filter( isset( $filtered[ $tab ] ) && is_array( $filtered[ $tab ] ) ? $filtered[ $tab ] : array(), 'is_string' ) );
	}

	/**
	 * The tab when it is known, otherwise the first one.
	 *
	 * @param string $tab Requested tab.
	 */
	public static function valid( string $tab ): string {
		return in_array( $tab, self::SLUGS, true ) ? $tab : 'connections';
	}
}
