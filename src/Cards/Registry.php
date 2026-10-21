<?php
/**
 * The cards.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Cards;

defined( 'ABSPATH' ) || exit;

/**
 * name => {title, template, script, keys, strings}. Tasks 9 to 13 fill `keys` (the browser
 * route keys of Api\Map the card calls) and `strings` (the card's JS strings, merged into
 * window.odoowooPortal.i18n); `template` and `script` are paths relative to the plugin's
 * templates/ and root folders (or an absolute template path and a script URL, for a card
 * added through the odoowoo_portal_cards filter).
 */
final class Registry {

	/**
	 * The cards, in the order the app lists them.
	 *
	 * @return array<string, array{title: string, template: string, script: string, keys: string[], strings: array<string, string>}>
	 */
	public static function cards(): array {
		$titles = array(
			'dashboard-list'           => __( 'Your dashboards', 'odoowoo-portal' ),
			'dashboard-header'         => __( 'Dashboard', 'odoowoo-portal' ),
			'woo-connection'           => __( 'WooCommerce connection', 'odoowoo-portal' ),
			'woo-settings'             => __( 'WooCommerce settings', 'odoowoo-portal' ),
			'odoo-connection'          => __( 'Odoo connection', 'odoowoo-portal' ),
			'odoo-settings'            => __( 'Odoo settings', 'odoowoo-portal' ),
			'payment-mappings'         => __( 'Payment method mappings', 'odoowoo-portal' ),
			'tax-mappings'             => __( 'Tax mappings', 'odoowoo-portal' ),
			'price-mappings'           => __( 'Price mappings', 'odoowoo-portal' ),
			'stock-mappings'           => __( 'Stock mappings', 'odoowoo-portal' ),
			'order-route-mappings'     => __( 'Order route mappings', 'odoowoo-portal' ),
			'order-pricelist-mappings' => __( 'Order pricelist mappings', 'odoowoo-portal' ),
			'gift-cards'               => __( 'Gift cards', 'odoowoo-portal' ),
			'manual-sync'              => __( 'Manual sync', 'odoowoo-portal' ),
			'sync-logs'                => __( 'Sync logs', 'odoowoo-portal' ),
		);

		$cards = array();

		foreach ( $titles as $name => $title ) {
			$cards[ $name ] = array(
				'title'    => $title,
				'template' => 'cards/' . $name . '.php',
				'script'   => 'assets/js/card-' . $name . '.js',
				'keys'     => array(),
				'strings'  => array(),
			);
		}

		/**
		 * Filters the cards (name => title, template, script, keys, strings).
		 *
		 * @param array $cards Cards.
		 */
		$filtered = apply_filters( 'odoowoo_portal_cards', $cards );

		return is_array( $filtered ) ? array_filter( $filtered, 'is_array' ) : $cards;
	}

	/**
	 * Is there a card with this name?
	 *
	 * @param string $name Card name.
	 */
	public static function has( string $name ): bool {
		return '' !== $name && array_key_exists( $name, self::cards() );
	}
}
