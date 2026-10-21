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

		$cards['dashboard-list']['keys']      = array( 'dashboard-list', 'dashboard-create', 'dashboard-pause', 'dashboard-delete' );
		$cards['dashboard-list']['strings']   = self::list_strings();
		$cards['dashboard-header']['keys']    = array( 'dashboard-detail', 'dashboard-rename' );
		$cards['dashboard-header']['strings'] = self::header_strings();

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

	/**
	 * The dashboard list card's strings. %s and %1$s are filled in by the script.
	 *
	 * @return array<string, string>
	 */
	private static function list_strings(): array {
		return array(
			'loading'         => __( 'Loading your dashboards…', 'odoowoo-portal' ),
			'newDashboard'    => __( 'New dashboard', 'odoowoo-portal' ),
			'newTitle'        => __( 'New dashboard', 'odoowoo-portal' ),
			'nameLabel'       => __( 'Dashboard name', 'odoowoo-portal' ),
			'planLabel'       => __( 'Plan', 'odoowoo-portal' ),
			'create'          => __( 'Create', 'odoowoo-portal' ),
			'creating'        => __( 'Creating…', 'odoowoo-portal' ),
			'nameRequired'    => __( 'Give the dashboard a name.', 'odoowoo-portal' ),
			'open'            => __( 'Open', 'odoowoo-portal' ),
			'pause'           => __( 'Pause', 'odoowoo-portal' ),
			'enable'          => __( 'Enable', 'odoowoo-portal' ),
			'delete'          => __( 'Delete', 'odoowoo-portal' ),
			/* translators: %s: dashboard name. */
			'openNamed'       => __( 'Open %s', 'odoowoo-portal' ),
			/* translators: %s: dashboard name. */
			'pauseNamed'      => __( 'Pause %s', 'odoowoo-portal' ),
			/* translators: %s: dashboard name. */
			'enableNamed'     => __( 'Enable %s', 'odoowoo-portal' ),
			/* translators: %s: dashboard name. */
			'deleteNamed'     => __( 'Delete %s', 'odoowoo-portal' ),
			'active'          => __( 'Active', 'odoowoo-portal' ),
			'paused'          => __( 'Paused', 'odoowoo-portal' ),
			/* translators: %s: date. */
			'created'         => __( 'Created %s', 'odoowoo-portal' ),
			'deleteTitle'     => __( 'Delete dashboard', 'odoowoo-portal' ),
			/* translators: %s: dashboard name. */
			'deleteBody'      => __( 'Delete %s? This removes its settings and logs. Stores already connected stop syncing.', 'odoowoo-portal' ),
			'emptyList'       => __( 'You have no dashboards yet. Create one to connect a store.', 'odoowoo-portal' ),
			'noPlan'          => __( 'You do not have an OdooWoo plan yet.', 'odoowoo-portal' ),
			/* translators: %s: number of dashboards. */
			'count'           => __( '%s dashboards', 'odoowoo-portal' ),
			'countOne'        => __( '1 dashboard', 'odoowoo-portal' ),
			/* translators: 1: dashboards used, 2: dashboards the plan allows. */
			'countOf'         => __( '%1$s of %2$s dashboards', 'odoowoo-portal' ),
			/* translators: %s: dashboards used. */
			'countOfOne'      => __( '%s of 1 dashboard', 'odoowoo-portal' ),
			/* translators: %s: number of dashboards. */
			'limitReached'    => __( 'You have reached your plan limit of %s dashboards.', 'odoowoo-portal' ),
			'limitReachedOne' => __( 'You have reached your plan limit of 1 dashboard.', 'odoowoo-portal' ),
		);
	}

	/**
	 * The dashboard header card's strings (Cancel comes with the core strings).
	 *
	 * @return array<string, string>
	 */
	private static function header_strings(): array {
		return array(
			'loading'      => __( 'Loading…', 'odoowoo-portal' ),
			'rename'       => __( 'Rename', 'odoowoo-portal' ),
			'save'         => __( 'Save', 'odoowoo-portal' ),
			'nameLabel'    => __( 'Dashboard name', 'odoowoo-portal' ),
			'nameRequired' => __( 'Give the dashboard a name.', 'odoowoo-portal' ),
			'active'       => __( 'Active', 'odoowoo-portal' ),
			'paused'       => __( 'Paused', 'odoowoo-portal' ),
			'notFound'     => \OdooWooPortal\Django\Messages::not_found(),
		);
	}
}
